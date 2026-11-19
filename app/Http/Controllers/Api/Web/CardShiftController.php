<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\CardShift;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CardShiftController extends Controller
{
    public function __construct(private readonly CardService $cards)
    {
    }

    public function current(): JsonResponse
    {
        $shift = CardShift::openFor(Auth::id());

        return response()->json([
            'shift' => $shift,
            'summary' => $shift ? $this->cards->shiftSummary($shift) : null,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_balance' => 'nullable|numeric|min:0',
        ]);

        $result = $this->cards->openShift(Auth::user(), $data['opening_balance'] ?? 0);

        return response()->json([
            'message' => $result['already_open'] ? 'Já tem um caixa de cartões aberto.' : 'Caixa de cartões aberto.',
            'shift' => $result['shift'],
        ]);
    }

    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'closing_balance' => 'required|numeric|min:0',
            'observation' => 'nullable|string|max:1000',
        ]);

        $result = $this->cards->closeShift(Auth::user(), $data['closing_balance'], $data['observation'] ?? null);

        return response()->json([
            'message' => 'Caixa de cartões fechado.',
            'shift' => $result['shift'],
            'summary' => $result['summary'],
        ]);
    }
}
