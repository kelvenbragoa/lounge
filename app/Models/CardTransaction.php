<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CardTransaction extends Model
{
    public const TYPE_DEPOSIT_IN = 'deposit_in';
    public const TYPE_TOPUP = 'topup';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_PURCHASE_REVERSAL = 'purchase_reversal';
    public const TYPE_REFUND_BALANCE = 'refund_balance';
    public const TYPE_DEPOSIT_OUT = 'deposit_out';
    public const TYPE_TRANSFER_IN = 'transfer_in';
    public const TYPE_TRANSFER_OUT = 'transfer_out';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'card_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'deposit_before',
        'deposit_after',
        'tender_method',
        'order_id',
        'payment_id',
        'cash_register_id',
        'related_transaction_id',
        'user_id',
        'request_id',
        'notes',
    ];

    protected $casts = [
        'card_id' => 'integer',
        'amount' => 'float',
        'balance_before' => 'float',
        'balance_after' => 'float',
        'deposit_before' => 'float',
        'deposit_after' => 'float',
        'order_id' => 'integer',
        'payment_id' => 'integer',
        'cash_register_id' => 'integer',
        'related_transaction_id' => 'integer',
        'user_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Transacções de cartão não podem ser alteradas. Registe uma transacção de correcção.');
        });

        static::deleting(function () {
            throw new LogicException('Transacções de cartão não podem ser apagadas. Registe uma transacção de correcção.');
        });
    }

    public function card()
    {
        return $this->belongsTo(Card::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Produtos da venda a que o movimento pertence (compras e estornos). Inclui itens
     * entretanto anulados (soft delete) para o extrato continuar a explicar o movimento.
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id')
            ->withTrashed()
            ->select(['order_items.id', 'order_items.order_id', 'order_items.product_id', 'order_items.quantity',
                'order_items.price', 'order_items.total', 'order_items.deleted_at'])
            ->with('product:id,name');
    }

    /**
     * Carrega o que o extrato precisa: operador, produtos e mesa da venda.
     */
    public function scopeWithStatement($query)
    {
        return $query->with(['user:id,name', 'items', 'order:id,table_id,total', 'order.table:id,name']);
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class)->withoutGlobalScope('sales');
    }

    public function related()
    {
        return $this->belongsTo(self::class, 'related_transaction_id');
    }
}
