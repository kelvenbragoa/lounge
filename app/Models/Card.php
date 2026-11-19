<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_LOST = 'lost';
    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'uid',
        'code',
        'holder_name',
        'holder_phone',
        'customer_id',
        'balance',
        'deposit_amount',
        'status',
        'registered_by',
        'registered_at',
        'replaced_by_card_id',
        'notes',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'balance' => 'float',
        'deposit_amount' => 'float',
        'registered_by' => 'integer',
        'registered_at' => 'datetime',
        'replaced_by_card_id' => 'integer',
    ];

    /**
     * Normaliza o UID vindo do NFC do telemóvel ("04:a1:b2..."), de leitores USB
     * ou de entrada manual para hex maiúsculo sem separadores.
     */
    public static function normalizeUid(?string $uid): string
    {
        return strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $uid));
    }

    public static function findByUid(?string $uid): ?self
    {
        $normalized = self::normalizeUid($uid);

        return $normalized === '' ? null : self::where('uid', $normalized)->first();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function transactions()
    {
        return $this->hasMany(CardTransaction::class)->orderByDesc('id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function replacedBy()
    {
        return $this->belongsTo(self::class, 'replaced_by_card_id');
    }
}
