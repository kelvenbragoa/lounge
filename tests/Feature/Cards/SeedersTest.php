<?php

namespace Tests\Feature\Cards;

use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodTableSeeder;
use Database\Seeders\RoleTableSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use DatabaseTransactions;

    private function seedAll(): void
    {
        $this->seed(RoleTableSeeder::class);
        $this->seed(PaymentMethodTableSeeder::class);
    }

    public function test_seeders_can_run_repeatedly_without_duplicating(): void
    {
        $this->seedAll();
        $methods = DB::table('payment_methods')->count();
        $roles = DB::table('roles')->count();

        $this->seedAll();

        $this->assertSame($methods, DB::table('payment_methods')->count());
        $this->assertSame($roles, DB::table('roles')->count());
    }

    public function test_seeders_define_card_role_and_payment_method_flags(): void
    {
        $this->seedAll();

        $this->assertSame('Caixa de Cartões', DB::table('roles')->where('id', 8)->value('name'));

        $card = PaymentMethod::card();
        $this->assertSame('Cartão Lounge', $card->name);
        $this->assertSame(1, (int) $card->counts_in_revenue);
        $this->assertSame(0, (int) $card->is_credit);

        $credit = PaymentMethod::where('name', 'Credito')->first();
        $this->assertSame([1, 0, 0], [(int) $credit->is_credit, (int) $credit->counts_in_revenue, (int) $credit->is_card]);

        $internal = PaymentMethod::where('name', 'Consumo Interno')->first();
        $this->assertSame([0, 0, 0], [(int) $internal->counts_in_revenue, (int) $internal->is_credit, (int) $internal->is_card]);

        $cash = PaymentMethod::where('name', 'Dinheiro')->first();
        $this->assertSame([1, 0, 0], [(int) $cash->counts_in_revenue, (int) $cash->is_credit, (int) $cash->is_card]);
    }

    public function test_rerunning_repairs_a_card_method_created_without_the_flag(): void
    {
        $this->seedAll();
        PaymentMethod::where('name', 'Cartão Lounge')->update(['is_card' => 0]);
        $this->assertNull(PaymentMethod::card());

        $this->seed(PaymentMethodTableSeeder::class);

        $this->assertSame('Cartão Lounge', PaymentMethod::card()->name);
    }
}
