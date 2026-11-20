<?php

namespace Tests\Feature\Cards;

use App\Models\CashRegister;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Em modo exclusivo (só cartão) o caixa de vendas não manuseia dinheiro:
 * abre sem fundo e fecha sozinho, sem contagem nem diferença.
 */
class CashlessRegisterTest extends TestCase
{
    use DatabaseTransactions;

    private array $headers;
    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->waiter = User::factory()->create(['role_id' => User::ROLE_WAITER]);
        $this->headers = ['token' => $this->waiter->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function openRegister(): CashRegister
    {
        return CashRegister::create([
            'user_id' => $this->waiter->id, 'cash_register_status_id' => 1, 'opening_balance' => 0, 'opened_at' => now(),
        ]);
    }

    public function test_web_open_ignores_opening_balance_when_cashless(): void
    {
        config(['cards.exclusive_payment' => true]);

        $this->postJson('/api/cashregisters/open', ['opening_balance' => 500], $this->headers)->assertOk();

        $register = CashRegister::where('user_id', $this->waiter->id)->latest('id')->first();
        $this->assertEquals(0, $register->opening_balance);
    }

    public function test_web_open_keeps_opening_balance_when_cash_is_accepted(): void
    {
        config(['cards.exclusive_payment' => false]);

        $this->postJson('/api/cashregisters/open', ['opening_balance' => 500], $this->headers)->assertOk();

        $this->assertEquals(500, CashRegister::where('user_id', $this->waiter->id)->latest('id')->first()->opening_balance);
    }

    public function test_web_close_is_automatic_when_cashless(): void
    {
        config(['cards.exclusive_payment' => true]);
        $register = $this->openRegister();

        $this->postJson('/api/cashregisters/close', [], $this->headers)->assertOk();

        $register->refresh();
        $this->assertSame(2, $register->cash_register_status_id);
        $this->assertEquals($register->automatic_closing_balance, $register->closing_balance);
        $this->assertEquals(0, $register->difference);
    }

    public function test_web_close_ignores_a_declared_amount_when_cashless(): void
    {
        config(['cards.exclusive_payment' => true]);
        $register = $this->openRegister();

        $this->postJson('/api/cashregisters/close', ['closing_balance' => 9999], $this->headers)->assertOk();

        $register->refresh();
        $this->assertEquals($register->automatic_closing_balance, $register->closing_balance);
        $this->assertEquals(0, $register->difference);
    }

    public function test_mobile_close_is_automatic_when_cashless(): void
    {
        config(['cards.exclusive_payment' => true]);
        $register = $this->openRegister();

        $this->postJson('/api/mobile-cashregister/close', [], $this->headers)->assertOk();

        $register->refresh();
        $this->assertSame(2, $register->cash_register_status_id);
        $this->assertEquals(0, $register->difference);
    }

    public function test_declared_amount_is_still_required_when_cash_is_accepted(): void
    {
        config(['cards.exclusive_payment' => false]);
        $register = $this->openRegister();

        $this->postJson('/api/mobile-cashregister/close', [], $this->headers)->assertStatus(422);
        $this->assertSame(1, $register->fresh()->cash_register_status_id);

        $this->postJson('/api/mobile-cashregister/close', ['closing_balance' => 5], $this->headers)->assertOk();

        $register->refresh();
        $this->assertEquals(5, $register->closing_balance);
        $this->assertEquals(-5, $register->difference);
    }

    public function test_settings_expose_exclusive_mode_to_clients(): void
    {
        config(['cards.exclusive_payment' => true]);
        $this->getJson('/api/cards/settings', $this->headers)->assertOk()->assertJsonPath('exclusive_payment', true);

        config(['cards.exclusive_payment' => false]);
        $this->getJson('/api/cards/settings', $this->headers)->assertOk()->assertJsonPath('exclusive_payment', false);
    }
}
