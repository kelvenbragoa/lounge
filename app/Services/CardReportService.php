<?php

namespace App\Services;

use App\Models\Card;
use App\Models\CardShift;
use App\Models\CardTransaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CardReportService
{
    private const INCOMING = [CardTransaction::TYPE_DEPOSIT_IN, CardTransaction::TYPE_TOPUP];
    private const OUTGOING = [CardTransaction::TYPE_REFUND_BALANCE, CardTransaction::TYPE_DEPOSIT_OUT];

    public function __construct(private readonly CardService $cards)
    {
    }

    /**
     * Resumo de um período: por caixa de cartões, totais, consumo, saldo em circulação e conciliação.
     * Com $userId, limita os dados de caixa a esse utilizador (consumo/circulação continuam globais).
     */
    public function dashboard(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        $cashMovements = CardTransaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('type', [...self::INCOMING, ...self::OUTGOING])
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->select(
                'user_id',
                'type',
                'tender_method',
                DB::raw('COUNT(*) as operations'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('user_id', 'type', 'tender_method')
            ->get();

        $in = "'".implode("','", self::INCOMING)."'";
        $out = "'".implode("','", self::OUTGOING)."'";
        $operationCounts = CardTransaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->select(
                'user_id',
                DB::raw("COUNT(DISTINCT CASE WHEN type IN ({$in}) THEN COALESCE(request_id, id) END) as topups"),
                DB::raw("COUNT(DISTINCT CASE WHEN type IN ({$out}) THEN COALESCE(request_id, id) END) as refunds")
            )
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $registrations = Card::query()
            ->whereBetween('registered_at', [$from, $to])
            ->when($userId, fn ($query) => $query->where('registered_by', $userId))
            ->select('registered_by', DB::raw('COUNT(*) as total'))
            ->groupBy('registered_by')
            ->pluck('total', 'registered_by');

        $shifts = CardShift::query()
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('opened_at', [$from, $to])
                    ->orWhereBetween('closed_at', [$from, $to])
                    ->orWhere(fn ($open) => $open->where('cash_register_status_id', CardShift::STATUS_OPEN)->where('opened_at', '<=', $to));
            })
            ->with('user:id,name')
            ->orderBy('opened_at')
            ->get();

        $userIds = $cashMovements->pluck('user_id')
            ->merge($registrations->keys())
            ->merge($shifts->pluck('user_id'))
            ->filter()
            ->unique()
            ->values();

        $users = User::whereIn('id', $userIds)->pluck('name', 'id');

        $perCashier = $userIds->map(function ($id) use ($cashMovements, $operationCounts, $registrations, $shifts, $users) {
            $rows = $cashMovements->where('user_id', $id);
            $userShifts = $shifts->where('user_id', $id);
            $closed = $userShifts->where('cash_register_status_id', CardShift::STATUS_CLOSED);

            $summary = $this->summarize($rows, collect([$operationCounts->get($id)])->filter());
            $opening = round((float) $userShifts->sum('opening_balance'), 2);

            return [
                'user_id' => (int) $id,
                'name' => $users[$id] ?? "#{$id}",
                'shifts' => $userShifts->count(),
                'open_shift_id' => $userShifts->firstWhere('cash_register_status_id', CardShift::STATUS_OPEN)?->id,
                'cards_registered' => (int) ($registrations[$id] ?? 0),
                ...$summary,
                'opening_balance' => $opening,
                'expected_cash' => round($opening + $summary['net_cash'], 2),
                'declared_cash' => round((float) $closed->sum('closing_balance'), 2),
                // Só turnos fechados têm valor declarado; diferença positiva = falta dinheiro.
                'difference' => round((float) $closed->sum('difference'), 2),
            ];
        })->sortBy('name')->values();

        $totalsSummary = $this->summarize($cashMovements, $operationCounts);
        $totalOpening = round((float) $shifts->sum('opening_balance'), 2);

        $totals = [
            'cards_registered' => (int) $registrations->sum(),
            ...$totalsSummary,
            'opening_balance' => $totalOpening,
            'expected_cash' => round($totalOpening + $totalsSummary['net_cash'], 2),
            'declared_cash' => round((float) $perCashier->sum('declared_cash'), 2),
            'difference' => round((float) $perCashier->sum('difference'), 2),
        ];

        return [
            'period' => ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()],
            'per_cashier' => $perCashier,
            'totals' => $totals,
            'consumption' => $this->consumption($from, $to),
            'outstanding' => $this->outstanding(),
            'reconciliation' => $this->reconciliation($from, $to),
            'open_shifts' => $shifts
                ->where('cash_register_status_id', CardShift::STATUS_OPEN)
                ->map(fn (CardShift $shift) => [
                    'id' => $shift->id,
                    'user_id' => $shift->user_id,
                    'name' => $shift->user?->name,
                    'opened_at' => $shift->opened_at?->toDateTimeString(),
                    'expected_cash' => $this->cards->shiftSummary($shift)['expected_cash'],
                ])
                ->values(),
        ];
    }

    /**
     * @param  Collection<int, object>  $rows  linhas agrupadas por type/tender_method
     * @param  Collection<int, object>  $counts  contagens de operações (topups/refunds) por utilizador
     */
    private function summarize(Collection $rows, Collection $counts): array
    {
        $sum = fn (array $types, ?string $tender = null) => (int) $rows
            ->filter(fn ($row) => in_array($row->type, $types, true) && ($tender === null || $row->tender_method === $tender))
            ->sum(fn ($row) => (int) round($row->total * 100));

        $byTender = [];
        foreach (config('cards.tender_methods') as $tender => $label) {
            $received = $sum(self::INCOMING, $tender);
            $paidOut = -$sum(self::OUTGOING, $tender);

            if ($received !== 0 || $paidOut !== 0) {
                $byTender[$tender] = [
                    'label' => $label,
                    'received' => $received / 100,
                    'paid_out' => $paidOut / 100,
                    'net' => ($received - $paidOut) / 100,
                ];
            }
        }

        $netCash = 0;
        foreach (config('cards.physical_cash_tender_methods') as $tender) {
            $netCash += $sum(self::INCOMING, $tender) + $sum(self::OUTGOING, $tender);
        }

        return [
            'topups_count' => (int) $counts->sum('topups'),
            'topups_total' => $sum([CardTransaction::TYPE_TOPUP]) / 100,
            'deposits_received' => $sum([CardTransaction::TYPE_DEPOSIT_IN]) / 100,
            'received_total' => $sum(self::INCOMING) / 100,
            'refunds_count' => (int) $counts->sum('refunds'),
            'balance_refunded' => -$sum([CardTransaction::TYPE_REFUND_BALANCE]) / 100,
            'deposits_refunded' => -$sum([CardTransaction::TYPE_DEPOSIT_OUT]) / 100,
            'paid_out_total' => -$sum(self::OUTGOING) / 100,
            'by_tender' => $byTender,
            'net_cash' => $netCash / 100,
        ];
    }

    private function consumption(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = CardTransaction::query()
            ->whereBetween('card_transactions.created_at', [$from, $to])
            ->whereIn('card_transactions.type', [CardTransaction::TYPE_PURCHASE, CardTransaction::TYPE_PURCHASE_REVERSAL])
            ->leftJoin('users', 'users.id', '=', 'card_transactions.user_id')
            ->select(
                'card_transactions.user_id',
                'users.name',
                'card_transactions.type',
                DB::raw('COUNT(*) as operations'),
                DB::raw('SUM(card_transactions.amount) as total')
            )
            ->groupBy('card_transactions.user_id', 'users.name', 'card_transactions.type')
            ->get();

        $purchases = $rows->where('type', CardTransaction::TYPE_PURCHASE);
        $reversals = $rows->where('type', CardTransaction::TYPE_PURCHASE_REVERSAL);

        $bySeller = $rows->groupBy('user_id')->map(function ($group, $id) {
            $gross = -(float) $group->where('type', CardTransaction::TYPE_PURCHASE)->sum('total');
            $reversed = (float) $group->where('type', CardTransaction::TYPE_PURCHASE_REVERSAL)->sum('total');

            return [
                'user_id' => (int) $id,
                'name' => $group->first()->name,
                'sales_count' => (int) $group->where('type', CardTransaction::TYPE_PURCHASE)->sum('operations'),
                'gross' => round($gross, 2),
                'reversed' => round($reversed, 2),
                'net' => round($gross - $reversed, 2),
            ];
        })->sortByDesc('net')->values();

        $gross = -(float) $purchases->sum('total');
        $reversed = (float) $reversals->sum('total');

        return [
            'sales_count' => (int) $purchases->sum('operations'),
            'gross' => round($gross, 2),
            'reversed' => round($reversed, 2),
            'net' => round($gross - $reversed, 2),
            'by_seller' => $bySeller,
        ];
    }

    /**
     * Dinheiro já recebido e ainda não consumido nem devolvido (passivo para com os clientes).
     */
    private function outstanding(): array
    {
        $byStatus = Card::query()
            ->select('status', DB::raw('COUNT(*) as cards'), DB::raw('SUM(balance) as balance'), DB::raw('SUM(deposit_amount) as deposits'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $balance = (float) $byStatus->sum('balance');
        $deposits = (float) $byStatus->sum('deposits');

        return [
            'balance' => round($balance, 2),
            'deposits' => round($deposits, 2),
            'total' => round($balance + $deposits, 2),
            'cards_by_status' => collect([Card::STATUS_ACTIVE, Card::STATUS_BLOCKED, Card::STATUS_LOST, Card::STATUS_RETURNED])
                ->mapWithKeys(fn ($status) => [$status => [
                    'cards' => (int) ($byStatus[$status]->cards ?? 0),
                    'balance' => round((float) ($byStatus[$status]->balance ?? 0), 2),
                    'deposits' => round((float) ($byStatus[$status]->deposits ?? 0), 2),
                ]]),
        ];
    }

    /**
     * Passivo no início + movimentos do período = passivo no fim; e o histórico tem de bater com os saldos dos cartões.
     */
    private function reconciliation(CarbonInterface $from, CarbonInterface $to): array
    {
        $cents = fn ($value) => (int) round(((float) $value) * 100);

        $opening = $cents(CardTransaction::where('created_at', '<', $from)->sum('amount'));
        $closing = $cents(CardTransaction::where('created_at', '<=', $to)->sum('amount'));

        $movements = CardTransaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->select('type', DB::raw('SUM(amount) as total'))
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($total) => $cents($total));

        $ledgerTotal = $cents(CardTransaction::sum('amount'));
        $cardsTotal = $cents(Card::sum(DB::raw('balance + deposit_amount')));

        return [
            'opening_liability' => $opening / 100,
            'movements' => $movements->map(fn ($value) => $value / 100),
            'closing_liability' => $closing / 100,
            'balanced' => $opening + $movements->sum() === $closing,
            'ledger_total' => $ledgerTotal / 100,
            'cards_total' => $cardsTotal / 100,
            'ledger_matches_cards' => $ledgerTotal === $cardsTotal,
        ];
    }
}
