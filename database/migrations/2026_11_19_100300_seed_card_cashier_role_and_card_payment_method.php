<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLE_ID = 8;
    private const ROLE_NAME = 'Caixa de Cartões';
    private const PAYMENT_METHOD_NAME = 'Cartão Lounge';

    public function up(): void
    {
        $existingRole = DB::table('roles')->where('id', self::ROLE_ID)->first();

        if ($existingRole && $existingRole->name !== self::ROLE_NAME) {
            throw new RuntimeException(
                'O role com id '.self::ROLE_ID.' já existe com o nome "'.$existingRole->name.'". '
                .'O código usa o id '.self::ROLE_ID.' para o Caixa de Cartões; resolva o conflito antes de migrar.'
            );
        }

        if (! $existingRole) {
            DB::table('roles')->insert([
                'id' => self::ROLE_ID,
                'name' => self::ROLE_NAME,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $method = DB::table('payment_methods')->where('name', self::PAYMENT_METHOD_NAME)->first();

        if ($method) {
            DB::table('payment_methods')->where('id', $method->id)->update([
                'is_card' => 1,
                'counts_in_revenue' => 1,
                'is_credit' => 0,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('payment_methods')->insert([
                'name' => self::PAYMENT_METHOD_NAME,
                'status_id' => 1,
                'counts_in_revenue' => 1,
                'is_credit' => 0,
                'is_card' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('payment_methods')->where('name', self::PAYMENT_METHOD_NAME)->delete();
        DB::table('roles')->where('id', self::ROLE_ID)->where('name', self::ROLE_NAME)->delete();
    }
};
