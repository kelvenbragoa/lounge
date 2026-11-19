<?php

namespace App\Services;

use App\Exceptions\CardException;
use App\Models\Card;
use App\Models\CardShift;
use App\Models\CardTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Única porta de entrada para alterar saldo/caução de cartões.
 * Valores são tratados internamente em cêntimos (int) para evitar erros de arredondamento.
 */
class CardService
{
    public function register(array $data, User $actor): Card
    {
        $uid = Card::normalizeUid($data['uid'] ?? null);

        if ($uid === '') {
            throw new CardException('UID do cartão inválido.', 'invalid_uid');
        }

        return DB::transaction(function () use ($uid, $data, $actor) {
            $card = Card::where('uid', $uid)->lockForUpdate()->first();

            if ($card && $card->status !== Card::STATUS_RETURNED) {
                throw new CardException('Este cartão já está registado.', 'card_already_registered', 409);
            }

            $code = isset($data['code']) && $data['code'] !== '' ? (string) $data['code'] : $card?->code;
            $this->assertCodeAvailable($code, $card?->id);

            $attributes = [
                'code' => $code,
                'holder_name' => $data['holder_name'] ?? null,
                'holder_phone' => $data['holder_phone'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'status' => Card::STATUS_ACTIVE,
                'registered_by' => $actor->id,
                'registered_at' => now(),
                'replaced_by_card_id' => null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($card) {
                if ($this->cents($card->balance) !== 0 || $this->cents($card->deposit_amount) !== 0) {
                    throw new LogicException("Cartão devolvido #{$card->id} ainda tem saldo ou caução.");
                }

                $card->update($attributes);
            } else {
                $card = Card::create(['uid' => $uid, 'balance' => 0, 'deposit_amount' => 0] + $attributes);
            }

            return $card->fresh();
        });
    }

    /**
     * @return array{card: Card, transactions: Collection<int, CardTransaction>, replayed: bool}
     */
    public function topup(Card $card, mixed $amount, string $tenderMethod, User $actor, string $requestId): array
    {
        $amountCents = $this->parseAmount($amount);

        if ($amountCents > $this->cents(config('cards.max_topup_amount'))) {
            throw new CardException(
                'Valor acima do limite de recarga ('.$this->format(config('cards.max_topup_amount')).' MT).',
                'topup_above_limit'
            );
        }

        $this->assertTenderMethod($tenderMethod);

        return DB::transaction(function () use ($card, $amountCents, $tenderMethod, $actor, $requestId) {
            $shift = $this->lockOpenShift($actor);
            $card = $this->lockCard($card->id);

            $replay = $this->findReplay($card, $requestId, [CardTransaction::TYPE_DEPOSIT_IN, CardTransaction::TYPE_TOPUP]);
            if ($replay->isNotEmpty()) {
                return ['card' => $card, 'transactions' => $replay, 'replayed' => true];
            }

            $this->assertActive($card);

            $requiredDeposit = $this->cents(config('cards.deposit_amount'));
            $missingDeposit = max(0, $requiredDeposit - $this->cents($card->deposit_amount));

            if ($missingDeposit > 0 && $amountCents < $missingDeposit) {
                throw new CardException(
                    'A primeira recarga deve ser de pelo menos '.$this->format($missingDeposit / 100).' MT (caução).',
                    'min_first_topup'
                );
            }

            $common = [
                'tender_method' => $tenderMethod,
                'cash_register_id' => $shift->id,
                'user_id' => $actor->id,
                'request_id' => $requestId,
            ];

            $transactions = collect();

            if ($missingDeposit > 0) {
                $transactions->push($this->record($card, CardTransaction::TYPE_DEPOSIT_IN, 0, $missingDeposit, $common));
            }

            $credit = $amountCents - $missingDeposit;
            if ($credit > 0) {
                $transactions->push($this->record($card, CardTransaction::TYPE_TOPUP, $credit, 0, $common));
            }

            return ['card' => $card->fresh(), 'transactions' => $transactions, 'replayed' => false];
        });
    }

    /**
     * Devolve saldo + caução ao cliente e liberta o cartão para novo registo.
     *
     * @return array{card: Card, transactions: Collection<int, CardTransaction>, total_paid: float, replayed: bool}
     */
    public function refund(Card $card, User $actor, string $requestId, string $tenderMethod = 'cash'): array
    {
        $this->assertTenderMethod($tenderMethod);

        return DB::transaction(function () use ($card, $actor, $requestId, $tenderMethod) {
            $shift = $this->lockOpenShift($actor);
            $card = $this->lockCard($card->id);

            $replay = $this->findReplay($card, $requestId, [CardTransaction::TYPE_REFUND_BALANCE, CardTransaction::TYPE_DEPOSIT_OUT]);
            if ($replay->isNotEmpty()) {
                return [
                    'card' => $card,
                    'transactions' => $replay,
                    'total_paid' => round(-$replay->sum('amount'), 2),
                    'replayed' => true,
                ];
            }

            if (! in_array($card->status, [Card::STATUS_ACTIVE, Card::STATUS_BLOCKED], true)) {
                throw CardException::notActive($card->status);
            }

            $hasOpenOrder = Order::where('card_id', $card->id)->whereIn('order_status_id', [1, 2])->exists();
            if ($hasOpenOrder) {
                throw new CardException(
                    'Este cartão está associado a uma mesa com conta aberta. Feche e pague a conta antes da devolução.',
                    'card_has_open_order',
                    409
                );
            }

            $balance = $this->cents($card->balance);
            $deposit = $this->cents($card->deposit_amount);

            $common = [
                'tender_method' => $tenderMethod,
                'cash_register_id' => $shift->id,
                'user_id' => $actor->id,
                'request_id' => $requestId,
            ];

            $transactions = collect();

            if ($balance > 0) {
                $transactions->push($this->record($card, CardTransaction::TYPE_REFUND_BALANCE, -$balance, 0, $common));
            }

            if ($deposit > 0) {
                $transactions->push($this->record($card, CardTransaction::TYPE_DEPOSIT_OUT, 0, -$deposit, $common));
            }

            $card->update(['status' => Card::STATUS_RETURNED]);

            return [
                'card' => $card->fresh(),
                'transactions' => $transactions,
                'total_paid' => ($balance + $deposit) / 100,
                'replayed' => false,
            ];
        });
    }

    public function block(Card $card, User $actor, bool $lost = false, ?string $reason = null): Card
    {
        return DB::transaction(function () use ($card, $actor, $lost, $reason) {
            $card = $this->lockCard($card->id);

            if ($card->status === Card::STATUS_RETURNED || $card->replaced_by_card_id) {
                throw CardException::notActive($card->status);
            }

            $card->update([
                'status' => $lost ? Card::STATUS_LOST : Card::STATUS_BLOCKED,
                'notes' => $this->appendNote($card->notes, ($lost ? 'Perdido' : 'Bloqueado')." por {$actor->name}".($reason ? ": {$reason}" : '')),
            ]);

            return $card->fresh();
        });
    }

    public function unblock(Card $card, User $actor): Card
    {
        return DB::transaction(function () use ($card, $actor) {
            $card = $this->lockCard($card->id);

            if (! in_array($card->status, [Card::STATUS_BLOCKED, Card::STATUS_LOST], true) || $card->replaced_by_card_id) {
                throw new CardException('Só é possível desbloquear cartões bloqueados ou perdidos que não foram substituídos.', 'cannot_unblock', 409);
            }

            $card->update([
                'status' => Card::STATUS_ACTIVE,
                'notes' => $this->appendNote($card->notes, "Desbloqueado por {$actor->name}"),
            ]);

            return $card->fresh();
        });
    }

    /**
     * Transfere saldo (e caução, conforme config) de um cartão perdido/danificado para um cartão novo.
     *
     * @return array{old_card: Card, new_card: Card, replayed: bool}
     */
    public function replace(Card $oldCard, string $newUid, User $actor, string $requestId): array
    {
        $newUid = Card::normalizeUid($newUid);

        if ($newUid === '') {
            throw new CardException('UID do cartão novo inválido.', 'invalid_uid');
        }

        return DB::transaction(function () use ($oldCard, $newUid, $actor, $requestId) {
            $old = $this->lockCard($oldCard->id);

            $replay = $this->findReplay($old, $requestId, [CardTransaction::TYPE_TRANSFER_OUT]);
            if ($replay->isNotEmpty()) {
                return ['old_card' => $old, 'new_card' => Card::findOrFail($old->replaced_by_card_id), 'replayed' => true];
            }

            if ($old->status === Card::STATUS_RETURNED || $old->replaced_by_card_id) {
                throw new CardException('Este cartão já foi devolvido ou substituído.', 'card_not_replaceable', 409);
            }

            if ($old->uid === $newUid) {
                throw new CardException('O cartão novo tem de ser diferente do antigo.', 'same_card');
            }

            $new = Card::where('uid', $newUid)->lockForUpdate()->first();
            if ($new && $new->status !== Card::STATUS_RETURNED) {
                throw new CardException('O cartão novo já está registado.', 'card_already_registered', 409);
            }

            $new = $this->register([
                'uid' => $newUid,
                'holder_name' => $old->holder_name,
                'holder_phone' => $old->holder_phone,
                'customer_id' => $old->customer_id,
                'notes' => "Substitui o cartão #{$old->id}",
            ], $actor);
            $new = $this->lockCard($new->id);

            $balance = $this->cents($old->balance);
            $deposit = config('cards.replace_transfers_deposit') ? $this->cents($old->deposit_amount) : 0;

            $common = [
                'cash_register_id' => CardShift::openFor($actor->id)?->id,
                'user_id' => $actor->id,
                'request_id' => $requestId,
            ];

            $out = $this->record($old, CardTransaction::TYPE_TRANSFER_OUT, -$balance, -$deposit, $common + [
                'notes' => "Transferido para o cartão #{$new->id}",
            ]);

            $this->record($new, CardTransaction::TYPE_TRANSFER_IN, $balance, $deposit, $common + [
                'related_transaction_id' => $out->id,
                'notes' => "Recebido do cartão #{$old->id}",
            ]);

            $old->update([
                'status' => $old->status === Card::STATUS_ACTIVE ? Card::STATUS_LOST : $old->status,
                'replaced_by_card_id' => $new->id,
                'notes' => $this->appendNote($old->notes, "Substituído pelo cartão #{$new->id} por {$actor->name}"),
            ]);

            return ['old_card' => $old->fresh(), 'new_card' => $new->fresh(), 'replayed' => false];
        });
    }

    /**
     * Debita uma compra. Deve ser chamado dentro da transacção que cria a venda/pagamento.
     */
    public function charge(
        Card $card,
        mixed $amount,
        User $actor,
        ?int $orderId = null,
        ?int $paymentId = null,
        ?int $cashRegisterId = null,
        ?string $requestId = null,
    ): CardTransaction {
        $amountCents = $this->parseAmount($amount);

        return DB::transaction(function () use ($card, $amountCents, $actor, $orderId, $paymentId, $cashRegisterId, $requestId) {
            $card = $this->lockCard($card->id);

            if ($requestId) {
                $replay = $this->findReplay($card, $requestId, [CardTransaction::TYPE_PURCHASE]);
                if ($replay->isNotEmpty()) {
                    return $replay->first();
                }
            }

            $this->assertActive($card);

            $balance = $this->cents($card->balance);
            if ($balance < $amountCents) {
                throw new CardException(
                    'Saldo insuficiente. Saldo: '.$this->format($balance / 100).' MT, total: '.$this->format($amountCents / 100).' MT.',
                    'insufficient_balance'
                );
            }

            return $this->record($card, CardTransaction::TYPE_PURCHASE, -$amountCents, 0, [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'cash_register_id' => $cashRegisterId,
                'user_id' => $actor->id,
                'request_id' => $requestId,
            ]);
        });
    }

    /**
     * Anula total ou parcialmente uma compra, devolvendo o valor ao cartão
     * (ou ao cartão que o substituiu).
     */
    public function reverse(
        CardTransaction $purchase,
        User $actor,
        mixed $amount = null,
        ?string $requestId = null,
        ?string $notes = null,
    ): CardTransaction {
        if ($purchase->type !== CardTransaction::TYPE_PURCHASE) {
            throw new LogicException('Só é possível anular transacções de compra.');
        }

        $requestedCents = $amount === null ? null : $this->parseAmount($amount);

        return DB::transaction(function () use ($purchase, $actor, $requestedCents, $requestId, $notes) {
            $target = $this->lockCard($this->resolveCurrentCardId($purchase->card_id));

            if ($requestId) {
                $replay = $this->findReplay($target, $requestId, [CardTransaction::TYPE_PURCHASE_REVERSAL]);
                if ($replay->isNotEmpty()) {
                    return $replay->first();
                }
            }

            if ($target->status === Card::STATUS_RETURNED) {
                throw new CardException(
                    'O cartão já foi devolvido. O valor tem de ser devolvido ao cliente em dinheiro pelo caixa de cartões.',
                    'card_returned',
                    409
                );
            }

            $alreadyReversed = $this->cents(
                CardTransaction::where('related_transaction_id', $purchase->id)
                    ->where('type', CardTransaction::TYPE_PURCHASE_REVERSAL)
                    ->sum('amount')
            );
            $remaining = -$this->cents($purchase->amount) - $alreadyReversed;
            $reverseCents = $requestedCents ?? $remaining;

            if ($reverseCents <= 0 || $reverseCents > $remaining) {
                throw new CardException(
                    'Valor de anulação inválido. Disponível para anular: '.$this->format($remaining / 100).' MT.',
                    'invalid_reversal_amount'
                );
            }

            return $this->record($target, CardTransaction::TYPE_PURCHASE_REVERSAL, $reverseCents, 0, [
                'order_id' => $purchase->order_id,
                'payment_id' => $purchase->payment_id,
                'cash_register_id' => $purchase->cash_register_id,
                'related_transaction_id' => $purchase->id,
                'user_id' => $actor->id,
                'request_id' => $requestId,
                'notes' => $notes,
            ]);
        });
    }

    public function openShift(User $actor, mixed $openingBalance = 0): array
    {
        $openingCents = $this->cents($openingBalance);

        if ($openingCents < 0) {
            throw new CardException('Fundo de caixa inválido.', 'invalid_amount');
        }

        return DB::transaction(function () use ($actor, $openingCents) {
            $existing = CardShift::where('user_id', $actor->id)
                ->where('cash_register_status_id', CardShift::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['shift' => $existing, 'already_open' => true];
            }

            $shift = CardShift::create([
                'user_id' => $actor->id,
                'cash_register_status_id' => CardShift::STATUS_OPEN,
                'opening_balance' => $openingCents / 100,
                'opened_at' => now(),
            ]);

            return ['shift' => $shift->fresh(), 'already_open' => false];
        });
    }

    public function closeShift(User $actor, mixed $declaredCash, ?string $observation = null): array
    {
        $declaredCents = $this->cents($declaredCash);

        if ($declaredCents < 0) {
            throw new CardException('Valor declarado inválido.', 'invalid_amount');
        }

        return DB::transaction(function () use ($actor, $declaredCents, $observation) {
            $shift = CardShift::where('user_id', $actor->id)
                ->where('cash_register_status_id', CardShift::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                throw new CardException('Não existe caixa de cartões aberto.', 'card_shift_closed', 409);
            }

            $summary = $this->shiftSummary($shift);
            $expectedCents = $this->cents($summary['expected_cash']);

            $shift->update([
                'closing_balance' => $declaredCents / 100,
                'automatic_closing_balance' => $expectedCents / 100,
                // Mesma convenção do caixa de vendas: positivo = falta dinheiro.
                'difference' => ($expectedCents - $declaredCents) / 100,
                'closed_at' => now(),
                'observation' => $observation,
                'cash_register_status_id' => CardShift::STATUS_CLOSED,
            ]);

            return ['shift' => $shift->fresh(), 'summary' => $summary];
        });
    }

    public function shiftSummary(CardShift $shift): array
    {
        $rows = CardTransaction::where('cash_register_id', $shift->id)
            ->select('type', 'tender_method', DB::raw('COUNT(*) as operations'), DB::raw('SUM(amount) as total'))
            ->groupBy('type', 'tender_method')
            ->get();

        $sum = fn (array $types, ?string $tender = null) => $rows
            ->filter(fn ($row) => in_array($row->type, $types, true) && ($tender === null || $row->tender_method === $tender))
            ->sum(fn ($row) => $this->cents($row->total));

        $incoming = [CardTransaction::TYPE_DEPOSIT_IN, CardTransaction::TYPE_TOPUP];
        $outgoing = [CardTransaction::TYPE_REFUND_BALANCE, CardTransaction::TYPE_DEPOSIT_OUT];

        $byTender = [];
        foreach (array_keys(config('cards.tender_methods')) as $tender) {
            $received = $sum($incoming, $tender);
            $paidOut = -$sum($outgoing, $tender);

            if ($received === 0 && $paidOut === 0) {
                continue;
            }

            $byTender[$tender] = [
                'label' => config("cards.tender_methods.{$tender}"),
                'received' => $received / 100,
                'paid_out' => $paidOut / 100,
                'net' => ($received - $paidOut) / 100,
            ];
        }

        $netCash = 0;
        foreach (config('cards.physical_cash_tender_methods') as $tender) {
            $netCash += $sum($incoming, $tender) + $sum($outgoing, $tender);
        }
        $openingCash = $this->cents($shift->opening_balance);

        $operationCount = fn (array $types) => CardTransaction::where('cash_register_id', $shift->id)
            ->whereIn('type', $types)
            ->distinct()
            ->count('request_id');

        $cardsRegistered = Card::where('registered_by', $shift->user_id)
            ->where('registered_at', '>=', $shift->opened_at ?? $shift->created_at)
            ->when($shift->closed_at, fn ($query) => $query->where('registered_at', '<=', $shift->closed_at))
            ->count();

        return [
            'shift_id' => $shift->id,
            'user_id' => $shift->user_id,
            'opened_at' => $shift->opened_at?->toDateTimeString() ?? $shift->created_at?->toDateTimeString(),
            'closed_at' => $shift->closed_at?->toDateTimeString(),
            'cards_registered' => $cardsRegistered,
            'topups_count' => $operationCount($incoming),
            'topups_total' => $sum([CardTransaction::TYPE_TOPUP]) / 100,
            'deposits_received' => $sum([CardTransaction::TYPE_DEPOSIT_IN]) / 100,
            'refunds_count' => $operationCount($outgoing),
            'balance_refunded' => -$sum([CardTransaction::TYPE_REFUND_BALANCE]) / 100,
            'deposits_refunded' => -$sum([CardTransaction::TYPE_DEPOSIT_OUT]) / 100,
            'by_tender' => $byTender,
            'opening_balance' => $openingCash / 100,
            'net_cash' => $netCash / 100,
            // Dinheiro físico que deve estar na gaveta: fundo inicial + entradas - saídas em dinheiro.
            'expected_cash' => ($openingCash + $netCash) / 100,
        ];
    }

    private function record(Card $card, string $type, int $balanceDeltaCents, int $depositDeltaCents, array $extra): CardTransaction
    {
        $balanceBefore = $this->cents($card->balance);
        $depositBefore = $this->cents($card->deposit_amount);
        $balanceAfter = $balanceBefore + $balanceDeltaCents;
        $depositAfter = $depositBefore + $depositDeltaCents;

        if ($balanceAfter < 0 || $depositAfter < 0) {
            throw new LogicException("Operação {$type} deixaria o cartão #{$card->id} com valor negativo.");
        }

        $transaction = CardTransaction::create($extra + [
            'card_id' => $card->id,
            'type' => $type,
            'amount' => ($balanceDeltaCents + $depositDeltaCents) / 100,
            'balance_before' => $balanceBefore / 100,
            'balance_after' => $balanceAfter / 100,
            'deposit_before' => $depositBefore / 100,
            'deposit_after' => $depositAfter / 100,
        ]);

        $card->update([
            'balance' => $balanceAfter / 100,
            'deposit_amount' => $depositAfter / 100,
        ]);

        return $transaction;
    }

    private function lockCard(int $cardId): Card
    {
        $card = Card::whereKey($cardId)->lockForUpdate()->first();

        if (! $card) {
            throw CardException::notFound();
        }

        return $card;
    }

    private function lockOpenShift(User $actor): CardShift
    {
        $shift = CardShift::where('user_id', $actor->id)
            ->where('cash_register_status_id', CardShift::STATUS_OPEN)
            ->sharedLock()
            ->first();

        if (! $shift) {
            throw CardException::shiftClosed();
        }

        return $shift;
    }

    /**
     * Procura transacções já gravadas com o mesmo request_id (reenvio do mesmo pedido).
     */
    private function findReplay(Card $card, string $requestId, array $types): Collection
    {
        $existing = CardTransaction::where('request_id', $requestId)
            ->whereIn('type', $types)
            ->orderBy('id')
            ->get();

        if ($existing->contains(fn ($transaction) => $transaction->card_id !== $card->id)) {
            throw new CardException('Este pedido já foi usado noutro cartão.', 'request_id_conflict', 409);
        }

        return $existing;
    }

    private function resolveCurrentCardId(int $cardId): int
    {
        $visited = [];

        while (true) {
            $replacement = Card::whereKey($cardId)->value('replaced_by_card_id');

            if (! $replacement || isset($visited[$replacement])) {
                return $cardId;
            }

            $visited[$cardId] = true;
            $cardId = (int) $replacement;
        }
    }

    private function assertActive(Card $card): void
    {
        if (! $card->isActive()) {
            throw CardException::notActive($card->status);
        }
    }

    private function assertTenderMethod(string $tenderMethod): void
    {
        if (! array_key_exists($tenderMethod, config('cards.tender_methods'))) {
            throw new CardException('Forma de pagamento da recarga inválida.', 'invalid_tender_method');
        }
    }

    private function assertCodeAvailable(?string $code, ?int $ignoreCardId): void
    {
        if ($code === null) {
            return;
        }

        $taken = Card::where('code', $code)
            ->when($ignoreCardId, fn ($query) => $query->where('id', '!=', $ignoreCardId))
            ->exists();

        if ($taken) {
            throw new CardException('Já existe um cartão com este número.', 'code_taken', 409);
        }
    }

    private function parseAmount(mixed $amount): int
    {
        if (! is_numeric($amount)) {
            throw new CardException('Valor inválido.', 'invalid_amount');
        }

        $cents = $this->cents($amount);

        if ($cents <= 0) {
            throw new CardException('O valor tem de ser maior que zero.', 'invalid_amount');
        }

        return $cents;
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function format(float|int $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    private function appendNote(?string $notes, string $line): string
    {
        return trim(($notes ? $notes."\n" : '').now()->format('d/m/Y H:i').' - '.$line);
    }
}
