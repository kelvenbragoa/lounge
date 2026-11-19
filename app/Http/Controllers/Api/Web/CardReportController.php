<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\CardShift;
use App\Models\CardTransaction;
use App\Models\User;
use App\Services\CardReportService;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CardReportController extends Controller
{
    public function __construct(
        private readonly CardReportService $reports,
        private readonly CardService $cards,
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json($this->reports->dashboard($from, $to, $this->scopedUserId($request)));
    }

    public function shifts(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $userId = $this->scopedUserId($request);

        $shifts = CardShift::query()
            ->with('user:id,name')
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->when($request->input('status') === 'open', fn ($query) => $query->where('cash_register_status_id', CardShift::STATUS_OPEN))
            ->when($request->input('status') === 'closed', fn ($query) => $query->where('cash_register_status_id', CardShift::STATUS_CLOSED))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('opened_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('opened_at', '<=', $request->input('date_to')))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($shifts);
    }

    public function shift(CardShift $cardShift): JsonResponse
    {
        $this->authorizeOwn($cardShift->user_id);

        return response()->json([
            'shift' => $cardShift->load('user:id,name'),
            'summary' => $this->cards->shiftSummary($cardShift),
            'transactions' => $cardShift->transactions()
                ->with(['card:id,uid,code,holder_name', 'user:id,name'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'string'],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $perPage = min(max((int) $request->input('per_page', 25), 5), 200);
        $userId = $this->scopedUserId($request);
        $types = array_filter(explode(',', (string) $request->input('type')));

        $transactions = CardTransaction::query()
            ->with(['card:id,uid,code,holder_name', 'user:id,name'])
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->when($types, fn ($query) => $query->whereIn('type', $types))
            ->when($request->filled('tender_method'), fn ($query) => $query->where('tender_method', $request->input('tender_method')))
            ->when($request->filled('card'), function ($query) use ($request) {
                $search = $request->input('card');
                $uid = Card::normalizeUid($search);

                $query->whereHas('card', fn ($card) => $card
                    ->where('code', $search)
                    ->when($uid !== '', fn ($byUid) => $byUid->orWhere('uid', $uid)));
            })
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->input('date_to')))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($transactions);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $from = Carbon::parse($data['date_from'] ?? today())->startOfDay();
        $to = Carbon::parse($data['date_to'] ?? $from)->endOfDay();

        return [$from, $to];
    }

    /**
     * Caixa de Cartões só vê os seus dados; Admin/Supervisor podem filtrar por user_id.
     */
    private function scopedUserId(Request $request): ?int
    {
        $user = Auth::user();

        if ((int) $user->role_id === User::ROLE_CARD_CASHIER) {
            return $user->id;
        }

        return $request->filled('user_id') ? (int) $request->input('user_id') : null;
    }

    private function authorizeOwn(int $ownerId): void
    {
        $user = Auth::user();

        if ((int) $user->role_id === User::ROLE_CARD_CASHIER && $user->id !== $ownerId) {
            abort(response()->json(['message' => 'Não tem permissão para ver este turno.'], 403));
        }
    }
}
