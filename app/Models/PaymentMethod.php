<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    //
    protected $guarded = [];

    protected $casts = [
        'is_card' => 'integer',
    ];

    public static function card(): ?self
    {
        return self::where('is_card', 1)->first();
    }

    public function status(){
        return $this->hasOne('App\Models\Status', 'id', 'status_id');
    }
}
