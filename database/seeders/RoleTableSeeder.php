<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Roles. Pode correr-se várias vezes: os ids são fixos (o código usa-os em
 * User::ROLE_* e nos middlewares role:...) e um role que já exista é deixado como está.
 */
class RoleTableSeeder extends Seeder
{
    private const ROLES = [
        1 => 'Administrador',
        2 => 'Supervisor',
        3 => 'Garçom',
        4 => 'Cozinha',
        5 => 'Bar',
        6 => 'Stock Manager',
        7 => 'Gestor de Mesas',
        8 => 'Caixa de Cartões',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $id => $name) {
            $existing = DB::table('roles')->where('id', $id)->first();

            if ($existing) {
                if ($existing->name !== $name) {
                    $this->command?->warn("Role {$id} já existe com o nome \"{$existing->name}\" (esperado \"{$name}\"); mantido.");
                }

                continue;
            }

            DB::table('roles')->insert([
                'id' => $id,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
