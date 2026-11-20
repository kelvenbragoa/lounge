<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\CardException;
use App\Models\Card;
use App\Models\CardTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\CardService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

trait ProcessesCardPayments
{
    /**
     * Métodos que o PDV pode mostrar. Em modo exclusivo: cartão e, só para os perfis
     * autorizados, consumo interno.
     */
    protected function saleablePaymentMethods(): Collection
    {
        $methods = PaymentMethod::all();

        if (! config('cards.exclusive_payment')) {
            return $methods;
        }

        return $methods->filter(fn (PaymentMethod $method) => $this->isPaymentMethodAllowed($method))->values();
    }

    protected function isPaymentMethodAllowed(PaymentMethod $method): bool
    {
        if ((int) $method->is_card === 1 || ! config('cards.exclusive_payment')) {
            return true;
        }

        return $this->isInternalConsumption($method) && $this->canUseInternalConsumption();
    }

    /**
     * Consumo interno: não é receita nem crédito, logo não envolve dinheiro do cliente.
     */
    protected function isInternalConsumption(PaymentMethod $method): bool
    {
        return (int) $method->is_card !== 1
            && (int) $method->counts_in_revenue === 0
            && (int) $method->is_credit === 0;
    }

    protected function canUseInternalConsumption(): bool
    {
        return in_array((int) Auth::user()?->role_id, config('cards.internal_consumption_roles', []), true);
    }

    /**
     * Valida o método de pagamento e devolve o cartão a debitar (null quando o método não é cartão).
     */
    protected function resolvePaymentCard(PaymentMethod $method, array $data, ?Order $order = null): ?Card
    {
        if ((int) $method->is_card !== 1) {
            if (! $this->isPaymentMethodAllowed($method)) {
                if ($this->isInternalConsumption($method)) {
                    throw new CardException(
                        'O consumo interno só pode ser lançado por um supervisor ou administrador.',
                        'internal_consumption_forbidden',
                        403
                    );
                }

                throw new CardException('O pagamento é exclusivamente por cartão. Leia o cartão do cliente.', 'card_payment_required');
            }

            return null;
        }

        if (! empty($data['card_uid'])) {
            $card = Card::findByUid($data['card_uid']);

            if (! $card) {
                throw new CardException('Cartão não registado.', 'card_not_found', 404);
            }

            return $card;
        }

        if ($order?->card_id) {
            return Card::find($order->card_id) ?? throw CardException::notFound();
        }

        throw new CardException('Leia o cartão do cliente para concluir o pagamento.', 'card_required');
    }

    /**
     * Debita o cartão dentro da transacção que criou o pagamento.
     */
    protected function chargePaymentCard(Card $card, float $amount, Order $order, Payment $payment, int $cashRegisterId): CardTransaction
    {
        $transaction = app(CardService::class)->charge(
            $card,
            $amount,
            Auth::user(),
            orderId: $order->id,
            paymentId: $payment->id,
            cashRegisterId: $cashRegisterId,
        );

        $payment->update(['card_id' => $card->id]);

        if (! $order->card_id) {
            $order->update(['card_id' => $card->id]);
        }

        return $transaction;
    }

    /**
     * Dados do cartão para a resposta de um pagamento: saldo antes/depois, UID mascarado, titular
     * e o id do movimento (serve para pedir o recibo). Sem cartão devolve só `card_balance => null`.
     */
    protected function cardPaymentSummary(?Card $card, ?int $orderId): array
    {
        if (! $card) {
            return ['card_balance' => null];
        }

        $transaction = CardTransaction::where('order_id', $orderId)
            ->where('card_id', $card->id)
            ->where('type', CardTransaction::TYPE_PURCHASE)
            ->latest('id')
            ->first();

        $card = $card->fresh();

        return [
            'card_balance' => $card->balance,
            'card_balance_before' => $transaction?->balance_before,
            'card_balance_after' => $transaction?->balance_after ?? $card->balance,
            'card_uid_masked' => $card->uid_masked,
            'card_holder_name' => $card->holder_name,
            'card_transaction_id' => $transaction?->id,
        ];
    }

    /**
     * Devolve ao cartão o valor de compras de uma venda (todo o valor ainda não anulado, ou só $amount).
     */
    protected function reverseCardPurchasesForOrder(Order $order, ?float $amount, string $notes): void
    {
        $purchases = CardTransaction::where('order_id', $order->id)
            ->where('type', CardTransaction::TYPE_PURCHASE)
            ->orderBy('id')
            ->get();

        if ($purchases->isEmpty()) {
            return;
        }

        $remainingCents = $amount === null ? null : (int) round($amount * 100);
        $service = app(CardService::class);

        foreach ($purchases as $purchase) {
            if ($remainingCents !== null && $remainingCents <= 0) {
                break;
            }

            $reversedCents = (int) round(CardTransaction::where('related_transaction_id', $purchase->id)
                ->where('type', CardTransaction::TYPE_PURCHASE_REVERSAL)
                ->sum('amount') * 100);
            $availableCents = (int) round(-$purchase->amount * 100) - $reversedCents;

            if ($availableCents <= 0) {
                continue;
            }

            $takeCents = $remainingCents === null ? $availableCents : min($availableCents, $remainingCents);

            $service->reverse($purchase, Auth::user(), $takeCents / 100, notes: $notes);

            if ($remainingCents !== null) {
                $remainingCents -= $takeCents;
            }
        }
    }

    /**
     * Associa (opcionalmente) um cartão à conta da mesa e garante que o saldo cobre
     * todas as contas abertas desse cartão. Chamar depois de actualizar o total do pedido.
     */
    protected function guardTableCard(Order $order, array $data): void
    {
        if (! empty($data['card_uid'])) {
            $card = Card::findByUid($data['card_uid']);

            if (! $card) {
                throw new CardException('Cartão não registado.', 'card_not_found', 404);
            }

            if ($order->card_id && $order->card_id !== $card->id) {
                throw new CardException('Esta mesa já está associada a outro cartão.', 'table_card_mismatch', 409);
            }

            if (! $order->card_id) {
                $order->update(['card_id' => $card->id]);
            }
        }

        if (! $order->card_id) {
            if (config('cards.require_card_on_table_open')) {
                throw new CardException('Leia o cartão do cliente para abrir a mesa.', 'card_required');
            }

            return;
        }

        $card = Card::whereKey($order->card_id)->lockForUpdate()->first() ?? throw CardException::notFound();

        if (! $card->isActive()) {
            throw CardException::notActive($card->status);
        }

        $openTotal = (float) Order::where('card_id', $card->id)
            ->whereIn('order_status_id', [1, 2])
            ->sum('total');

        if (round($card->balance * 100) < round($openTotal * 100)) {
            throw new CardException(
                'Saldo do cartão insuficiente para este pedido. Saldo: '.number_format($card->balance, 2, ',', '.')
                .' MT, consumo em aberto: '.number_format($openTotal, 2, ',', '.').' MT. Recarregue o cartão.',
                'insufficient_balance'
            );
        }
    }

    protected function assertServerTotalMatches(float $serverTotal, mixed $clientTotal): void
    {
        if (abs(round($serverTotal * 100) - round(((float) $clientTotal) * 100)) > 0) {
            throw new RuntimeException('O total da venda não corresponde aos produtos. Actualize a página e tente novamente.');
        }
    }
}
