<?php

namespace App\Services;

use App\Models\Card;
use App\Models\CardTransaction;
use App\Models\PaymentMethod;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recibo (PDF 80 mm) de uma operação com cartão: recarga, devolução, compra, estorno,
 * substituição ou ajuste. Tudo vem dos movimentos gravados (que nunca são alterados),
 * por isso uma reimpressão sai igual ao original.
 */
class CardReceiptService
{
    /**
     * Uma recarga ou devolução gera vários movimentos com o mesmo request_id
     * (ex.: caução + saldo); o recibo junta-os.
     *
     * @return Collection<int, CardTransaction>
     */
    public function group(CardTransaction $transaction): Collection
    {
        if (! $transaction->request_id) {
            return new Collection([$transaction]);
        }

        return CardTransaction::where('card_id', $transaction->card_id)
            ->where('request_id', $transaction->request_id)
            ->orderBy('id')
            ->get();
    }

    public function data(CardTransaction $transaction, bool $copy = false): array
    {
        $group = $this->group($transaction);
        $group->load(['user:id,name', 'items', 'order:id,table_id,total', 'order.table:id,name']);

        $first = $group->first();
        $last = $group->last();
        $card = Card::find($first->card_id);
        $types = $group->pluck('type');

        $balanceDelta = round($last->balance_after - $first->balance_before, 2);
        $depositDelta = round($last->deposit_after - $first->deposit_before, 2);

        $kind = match (true) {
            $types->contains(CardTransaction::TYPE_PURCHASE) => 'purchase',
            $types->contains(CardTransaction::TYPE_PURCHASE_REVERSAL) => 'reversal',
            $types->contains(CardTransaction::TYPE_REFUND_BALANCE) || $types->contains(CardTransaction::TYPE_DEPOSIT_OUT) => 'refund',
            $types->contains(CardTransaction::TYPE_TOPUP) || $types->contains(CardTransaction::TYPE_DEPOSIT_IN) => 'topup',
            $types->contains(CardTransaction::TYPE_TRANSFER_OUT) => 'transfer_out',
            $types->contains(CardTransaction::TYPE_TRANSFER_IN) => 'transfer_in',
            default => 'adjustment',
        };

        $rows = [];
        $items = [];
        $tender = $this->tenderLabel($group->firstWhere('tender_method')?->tender_method);

        switch ($kind) {
            case 'topup':
                $rows[] = ['Forma de pagamento', $tender];
                $rows[] = ['Valor recebido', $this->mt($balanceDelta + $depositDelta), true];
                if ($depositDelta > 0) {
                    $rows[] = ['Caução retida', $this->mt($depositDelta)];
                }
                $rows[] = ['Valor creditado', $this->mt($balanceDelta)];
                break;

            case 'refund':
                $rows[] = ['Saldo devolvido', $this->mt(-$balanceDelta)];
                $rows[] = ['Caução devolvida', $this->mt(-$depositDelta)];
                $rows[] = ['Total entregue', $this->mt(-$balanceDelta - $depositDelta), true];
                $rows[] = ['Forma de entrega', $tender];
                break;

            case 'purchase':
            case 'reversal':
                $order = $first->order;
                $rows[] = [$order?->table?->name ? 'Mesa' : 'Origem', $order?->table?->name ?? 'Venda rápida'];
                if ($first->order_id) {
                    $rows[] = ['Pedido', '#'.$first->order_id];
                }
                if ($kind === 'purchase') {
                    $rows[] = ['Pagamento', PaymentMethod::card()?->name ?? 'Cartão'];
                    $items = $first->items
                        ->whereNull('deleted_at')
                        ->map(fn ($item) => [
                            'name' => $item->product?->name ?? 'Produto',
                            'quantity' => (int) $item->quantity,
                            'price' => (float) $item->price,
                            'total' => (float) $item->total,
                        ])->values()->all();
                    $rows[] = ['Total pago', $this->mt(-$balanceDelta), true];
                } else {
                    $rows[] = ['Valor estornado', $this->mt($balanceDelta), true];
                }
                break;

            case 'transfer_out':
                $rows[] = ['Estado', 'Cartão substituído'];
                $rows[] = ['Saldo transferido', $this->mt(-$balanceDelta), true];
                $rows[] = ['Caução transferida', $this->mt(-$depositDelta)];
                break;

            case 'transfer_in':
                $rows[] = ['Origem', 'Substitui outro cartão'];
                $rows[] = ['Saldo recebido', $this->mt($balanceDelta), true];
                $rows[] = ['Caução recebida', $this->mt($depositDelta)];
                break;

            default:
                $rows[] = ['Ajuste', $this->mt($balanceDelta), true];
                if ($first->notes) {
                    $rows[] = ['Nota', $first->notes];
                }
        }

        return [
            'kind' => $kind,
            'title' => $this->title($kind),
            'copy' => $copy,
            'number' => sprintf('%06d', $first->id),
            'date' => $first->created_at,
            'operator' => $first->user?->name ?? '-',
            'card_masked' => Card::maskUid($card?->uid),
            'holder_name' => $card?->holder_name,
            'rows' => $rows,
            'items' => $items,
            'balance_before' => $this->mt($first->balance_before),
            'balance_after' => $this->mt($last->balance_after),
            'deposit_after' => $last->deposit_after > 0 ? $this->mt($last->deposit_after) : null,
            'notes' => $kind === 'reversal' ? $first->notes : null,
        ];
    }

    public function pdf(CardTransaction $transaction, bool $copy = false)
    {
        $data = $this->data($transaction, $copy);

        // Papel de 80 mm com altura ajustada ao conteúdo (não corta recibos com muitos produtos).
        $height = 225 + count($data['items']) * 22 + count($data['rows']) * 16 + ($data['holder_name'] ? 16 : 0)
            + ($data['copy'] ? 22 : 0) + ($data['notes'] ? 24 : 0);

        return Pdf::loadView('pdf.cardreceipt', $data)
            ->setOptions(['defaultFont' => 'courier', 'isRemoteEnabled' => false])
            ->setPaper([0, 0, 226.77, $height])
            ->stream('recibo-cartao-'.$data['number'].'.pdf');
    }

    private function title(string $kind): string
    {
        return match ($kind) {
            'topup' => 'RECIBO DE RECARGA',
            'refund' => 'RECIBO DE DEVOLUÇÃO',
            'purchase' => 'RECIBO DE COMPRA',
            'reversal' => 'COMPROVATIVO DE ESTORNO',
            'transfer_out', 'transfer_in' => 'SUBSTITUIÇÃO DE CARTÃO',
            default => 'COMPROVATIVO DE AJUSTE',
        };
    }

    private function tenderLabel(?string $tender): string
    {
        if (! $tender) {
            return '-';
        }

        return config("cards.tender_methods.{$tender}") ?? ucfirst($tender);
    }

    private function mt(float|int|string|null $value): string
    {
        return number_format((float) $value, 2, ',', '.').' MT';
    }
}
