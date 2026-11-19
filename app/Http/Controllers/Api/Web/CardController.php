<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\CardTransaction;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CardController extends Controller
{
    public function __construct(private readonly CardService $cards)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        $sortBy = $request->input('sort_by', 'id');
        if (! in_array($sortBy, ['id', 'uid', 'code', 'holder_name', 'balance', 'status', 'registered_at'], true)) {
            $sortBy = 'id';
        }
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $cards = Card::query()
            ->with('registeredBy:id,name')
            ->when($request->filled('query'), function ($query) use ($request) {
                $search = $request->input('query');
                $uid = Card::normalizeUid($search);

                $query->where(function ($searchQuery) use ($search, $uid) {
                    $searchQuery
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('holder_name', 'like', "%{$search}%")
                        ->orWhere('holder_phone', 'like', "%{$search}%");

                    if ($uid !== '') {
                        $searchQuery->orWhere('uid', 'like', "%{$uid}%");
                    }
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($cards);
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uid' => 'required|string|max:64',
        ]);

        $card = Card::findByUid($data['uid']);

        if (! $card) {
            return response()->json([
                'message' => 'Cartão não registado.',
                'code' => 'card_not_found',
                'uid' => Card::normalizeUid($data['uid']),
            ], 404);
        }

        return response()->json([
            'card' => $card->load('registeredBy:id,name'),
            'transactions' => $card->transactions()->with('user:id,name')->limit(20)->get(),
        ]);
    }

    public function show(Card $card): JsonResponse
    {
        return response()->json([
            'card' => $card->load(['registeredBy:id,name', 'customer', 'replacedBy:id,uid,code']),
        ]);
    }

    public function transactions(Request $request, Card $card): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 20), 5), 100);

        return response()->json(
            $card->transactions()->with('user:id,name')->paginate($perPage)->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uid' => 'required|string|max:64',
            'code' => 'nullable|string|max:32',
            'holder_name' => 'nullable|string|max:255',
            'holder_phone' => 'nullable|string|max:32',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $card = $this->cards->register($data, Auth::user());

        return response()->json([
            'message' => 'Cartão registado com sucesso.',
            'card' => $card,
        ], 201);
    }

    public function topup(Request $request, Card $card): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'tender_method' => ['required', 'string', Rule::in(array_keys(config('cards.tender_methods')))],
            'request_id' => 'required|string|max:64',
        ]);

        $result = $this->cards->topup($card, $data['amount'], $data['tender_method'], Auth::user(), $data['request_id']);

        $deposit = $result['transactions']->firstWhere('type', CardTransaction::TYPE_DEPOSIT_IN);
        $credited = $result['transactions']->firstWhere('type', CardTransaction::TYPE_TOPUP);

        $message = 'Cartão recarregado com '.number_format((float) $data['amount'], 2, ',', '.').' MT.';
        if ($deposit) {
            $message .= ' Caução retida: '.number_format($deposit->amount, 2, ',', '.').' MT.';
        }
        $message .= ' Saldo actual: '.number_format($result['card']->balance, 2, ',', '.').' MT.';

        return response()->json([
            'message' => $result['replayed'] ? 'Recarga já registada anteriormente.' : $message,
            'card' => $result['card'],
            'transactions' => $result['transactions'],
            'deposit_retained' => $deposit?->amount ?? 0,
            'credited' => $credited?->amount ?? 0,
            'replayed' => $result['replayed'],
        ]);
    }

    public function refund(Request $request, Card $card): JsonResponse
    {
        $data = $request->validate([
            'request_id' => 'required|string|max:64',
            'tender_method' => ['nullable', 'string', Rule::in(array_keys(config('cards.tender_methods')))],
        ]);

        $result = $this->cards->refund($card, Auth::user(), $data['request_id'], $data['tender_method'] ?? 'cash');

        return response()->json([
            'message' => $result['replayed']
                ? 'Devolução já registada anteriormente.'
                : 'Devolução concluída. Entregar ao cliente '.number_format($result['total_paid'], 2, ',', '.').' MT.',
            'card' => $result['card'],
            'transactions' => $result['transactions'],
            'total_paid' => $result['total_paid'],
            'replayed' => $result['replayed'],
        ]);
    }

    public function block(Request $request, Card $card): JsonResponse
    {
        $data = $request->validate([
            'lost' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $card = $this->cards->block($card, Auth::user(), (bool) ($data['lost'] ?? false), $data['reason'] ?? null);

        return response()->json([
            'message' => $card->status === Card::STATUS_LOST ? 'Cartão marcado como perdido.' : 'Cartão bloqueado.',
            'card' => $card,
        ]);
    }

    public function unblock(Card $card): JsonResponse
    {
        $card = $this->cards->unblock($card, Auth::user());

        return response()->json([
            'message' => 'Cartão desbloqueado.',
            'card' => $card,
        ]);
    }

    public function replace(Request $request, Card $card): JsonResponse
    {
        $data = $request->validate([
            'new_uid' => 'required|string|max:64',
            'request_id' => 'required|string|max:64',
        ]);

        $result = $this->cards->replace($card, $data['new_uid'], Auth::user(), $data['request_id']);

        return response()->json([
            'message' => $result['replayed']
                ? 'Substituição já registada anteriormente.'
                : 'Saldo transferido para o cartão novo.',
            'old_card' => $result['old_card'],
            'new_card' => $result['new_card'],
            'replayed' => $result['replayed'],
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'deposit_amount' => (float) config('cards.deposit_amount'),
            'max_topup_amount' => (float) config('cards.max_topup_amount'),
            'tender_methods' => collect(config('cards.tender_methods'))
                ->map(fn ($label, $key) => ['id' => $key, 'name' => $label])
                ->values(),
        ]);
    }
}
