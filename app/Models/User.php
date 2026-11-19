<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    public const ROLE_ADMIN = 1;
    public const ROLE_SUPERVISOR = 2;
    public const ROLE_WAITER = 3;
    public const ROLE_KITCHEN = 4;
    public const ROLE_BAR = 5;
    public const ROLE_STOCK_MANAGER = 6;
    public const ROLE_TABLE_MANAGER = 7;
    public const ROLE_CARD_CASHIER = 8;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role_id' => 'integer'
        ];
    }

    public function role(){
        return $this->hasOne('App\Models\Role', 'id', 'role_id');
    }

    public function orderitens()
    {
        return $this->hasMany(OrderItem::class);
    }
}
