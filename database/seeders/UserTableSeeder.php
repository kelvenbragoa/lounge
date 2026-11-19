<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        DB::table('users')->insert([
            [
                "name"=>"Administrador",
                "role_id"=>1,
                "email"=>"admin@test.com",
                "password"=>bcrypt('12345678'),
                "created_at"=>now(),
                "updated_at"=>now(),
            ],
            [
                "name"=>"Supervisor",
                "role_id"=>2,
                "email"=>"supervisor@test.com",
                "password"=>bcrypt('12345678'),
                "created_at"=>now(),
                "updated_at"=>now(),
            ],
            [
                "name"=>"Garcom",
                "role_id"=>3,
                "email"=>"garcom@test.com",
                "password"=>bcrypt('12345678'),
                "created_at"=>now(),
                "updated_at"=>now(),
            ],
            [
                "name"=>"Cozinha",
                "role_id"=>4,
                "email"=>"cozinha@test.com",
                "password"=>bcrypt('12345678'),
                "created_at"=>now(),
                "updated_at"=>now(),
            ],
            [
                "name"=>"Bar",
                "role_id"=>5,
                "email"=>"bar@test.com",
                "password"=>bcrypt('12345678'),
                "created_at"=>now(),
                "updated_at"=>now(),
            ],
            [
                "name"=>"Stock Manager",
                "role_id"=>6,
                "email"=>"stockmanager@test.com",
                "password"=>bcrypt('12345678'),
                "created_at"=>now(),
                "updated_at"=>now(),
            ],
            [
                "name"=>"Gestor de Mesas",
                "role_id"=>7,
                "email"=>"gestormesas@test.com",
                "password"=>bcrypt('12345678'),
                "created_at"=>now(),
                "updated_at"=>now(),
            ],
            [
                "name"=>"Caixa de Cartões",
                "role_id"=>8,
                "email"=>"caixacartoes@test.com",
                "password"=>bcrypt('12345678'),
                "created_at"=>now(),
                "updated_at"=>now(),
            ],
        ]);
    }
}
