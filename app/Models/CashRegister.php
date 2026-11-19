<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Caixa de vendas. Os turnos do caixa de cartões vivem na mesma tabela (kind = cards)
 * e são acedidos pelo modelo CardShift; o escopo global impede que o código de vendas os veja.
 */
class CashRegister extends Model
{
    //
    protected $guarded = [];

    protected $attributes = [
        'kind' => self::KIND_SALES,
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('sales', function (Builder $query) {
            $query->where($query->getModel()->getTable().'.kind', self::KIND_SALES);
        });
    }

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'opening_balance' => 'float',
        'closing_balance' => 'float',
        'difference' => 'float',
        'automatic_closing_balance' => 'float',
        'cash_register_status_id' => 'integer',
    ];

    public const KIND_SALES = 'sales';
    public const KIND_CARDS = 'cards';
    public function orderitens()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function orderItensTotal()
    {
        return $this->orderitens()->sum('total');
    }

    protected function orderItensTotalSum(): CashRegister
{
    return CashRegister::get(fn () => $this->orderitens()->sum('total'));
}

    public function user(){
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }
    public function status(){
        return $this->hasOne('App\Models\CashRegisterStatus', 'id', 'cash_register_status_id');
    }
}
