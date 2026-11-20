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

    /**
     * Em modo exclusivo (só cartão) quem vende não manuseia dinheiro: o caixa de vendas é
     * apenas um turno, sem fundo inicial nem contagem no fecho. O dinheiro vive nos
     * turnos do caixa de cartões.
     */
    public static function isCashless(): bool
    {
        return (bool) config('cards.exclusive_payment');
    }

    /**
     * Valores a gravar no fecho. Sem dinheiro: o fecho é automático (sem diferença).
     * Com dinheiro: usa o valor contado pelo operador e a diferença face ao sistema.
     *
     * @return array{closing_balance: float, difference: float}
     */
    public static function closingFigures(float $systemTotal, mixed $declared): array
    {
        if (self::isCashless()) {
            return ['closing_balance' => $systemTotal, 'difference' => 0.0];
        }

        $declared = (float) $declared;

        return ['closing_balance' => $declared, 'difference' => $systemTotal - $declared];
    }
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
