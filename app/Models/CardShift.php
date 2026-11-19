<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Turno do caixa de cartões (registo em cash_registers com kind = cards).
 */
class CardShift extends Model
{
    public const STATUS_OPEN = 1;
    public const STATUS_CLOSED = 2;

    protected $table = 'cash_registers';

    protected $guarded = [];

    protected $attributes = [
        'kind' => CashRegister::KIND_CARDS,
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'opening_balance' => 'float',
        'closing_balance' => 'float',
        'difference' => 'float',
        'automatic_closing_balance' => 'float',
        'cash_register_status_id' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('cards', function (Builder $query) {
            $query->where('cash_registers.kind', CashRegister::KIND_CARDS);
        });
    }

    public static function openFor(int $userId): ?self
    {
        return self::where('user_id', $userId)
            ->where('cash_register_status_id', self::STATUS_OPEN)
            ->latest('id')
            ->first();
    }

    public function isOpen(): bool
    {
        return $this->cash_register_status_id === self::STATUS_OPEN;
    }

    public function transactions()
    {
        return $this->hasMany(CardTransaction::class, 'cash_register_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
