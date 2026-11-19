<?php

namespace Tests\Feature\Cards;

use App\Models\CardShift;
use App\Models\User;
use App\Services\CardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardReportTest extends TestCase
{
    use DatabaseTransactions;

    private CardService $service;
    private User $anna;
    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cards.deposit_amount' => 100]);
        $this->service = app(CardService::class);
        $this->anna = User::factory()->create(['role_id' => User::ROLE_CARD_CASHIER, 'name' => 'Anna']);
        $this->bruno = User::factory()->create(['role_id' => User::ROLE_CARD_CASHIER, 'name' => 'Bruno']);
    }

    private function headers(User $user): array
    {
        return ['token' => $user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function scenario(): void
    {
        $this->service->openShift($this->anna);
        $this->service->openShift($this->bruno, 1000); // fundo de caixa para devoluções

        $c1 = $this->service->register(['uid' => 'A1'.bin2hex(random_bytes(4))], $this->anna);
        $c2 = $this->service->register(['uid' => 'A2'.bin2hex(random_bytes(4))], $this->anna);
        $c3 = $this->service->register(['uid' => 'A3'.bin2hex(random_bytes(4))], $this->bruno);

        $this->service->topup($c1, 1000, 'cash', $this->anna, (string) Str::uuid());   // 100 caução + 900
        $this->service->topup($c2, 500, 'mpesa', $this->anna, (string) Str::uuid());   // 100 caução + 400
        $this->service->topup($c3, 300, 'cash', $this->bruno, (string) Str::uuid());   // 100 caução + 200

        $waiter = User::factory()->create(['role_id' => User::ROLE_WAITER, 'name' => 'Garçom']);
        $purchase = $this->service->charge($c1, 400, $waiter, orderId: 1);
        $this->service->reverse($purchase, $waiter, 50);
        $this->service->charge($c3, 200, $waiter, orderId: 2);

        $this->service->refund($c1, $this->bruno, (string) Str::uuid()); // devolve 550 + 100 em dinheiro (Bruno)

        $this->service->closeShift($this->anna, 1000);
    }

    public function test_dashboard_aggregates_per_cashier_and_reconciles(): void
    {
        $this->scenario();
        $admin = User::factory()->create(['role_id' => User::ROLE_ADMIN]);

        $data = $this->getJson('/api/card-reports/dashboard', $this->headers($admin))->assertOk()->json();

        $anna = collect($data['per_cashier'])->firstWhere('name', 'Anna');
        $bruno = collect($data['per_cashier'])->firstWhere('name', 'Bruno');

        $this->assertSame(2, $anna['cards_registered']);
        $this->assertSame(2, $anna['topups_count']);
        $this->assertEquals(1300, $anna['topups_total']);
        $this->assertEquals(200, $anna['deposits_received']);
        $this->assertEquals(1000, $anna['expected_cash']);
        $this->assertEquals(500, $anna['by_tender']['mpesa']['received']);
        $this->assertEquals(1000, $anna['declared_cash']);
        $this->assertEquals(0, $anna['difference']);
        $this->assertNull($anna['open_shift_id']);

        $this->assertSame(1, $bruno['refunds_count']);
        $this->assertEquals(550, $bruno['balance_refunded']);
        $this->assertEquals(100, $bruno['deposits_refunded']);
        $this->assertEquals(1000, $bruno['opening_balance']);
        $this->assertEquals(300 - 650, $bruno['net_cash']);
        $this->assertEquals(1000 + 300 - 650, $bruno['expected_cash']);
        $this->assertNotNull($bruno['open_shift_id']);

        $this->assertEquals(1000 + 650, $data['totals']['expected_cash']);
        $this->assertSame(3, $data['totals']['cards_registered']);

        $this->assertSame(2, $data['consumption']['sales_count']);
        $this->assertEquals(600, $data['consumption']['gross']);
        $this->assertEquals(50, $data['consumption']['reversed']);
        $this->assertEquals(550, $data['consumption']['net']);

        // Restam c2 (400 + 100) e c3 (0 + 100)
        $this->assertEquals(400, $data['outstanding']['balance']);
        $this->assertEquals(200, $data['outstanding']['deposits']);
        $this->assertSame(1, $data['outstanding']['cards_by_status']['returned']['cards']);

        $this->assertTrue($data['reconciliation']['balanced']);
        $this->assertTrue($data['reconciliation']['ledger_matches_cards']);
        $this->assertEquals(600, $data['reconciliation']['closing_liability']);

        $this->assertCount(1, $data['open_shifts']);
        $this->assertSame($this->bruno->id, $data['open_shifts'][0]['user_id']);
    }

    public function test_period_filter_excludes_other_days(): void
    {
        Carbon::setTestNow(now()->subDay());
        $this->service->openShift($this->anna);
        $card = $this->service->register(['uid' => '0D'.bin2hex(random_bytes(4))], $this->anna);
        $this->service->topup($card, 1000, 'cash', $this->anna, (string) Str::uuid());
        Carbon::setTestNow();

        $this->service->topup($card, 200, 'cash', $this->anna, (string) Str::uuid());

        $admin = User::factory()->create(['role_id' => User::ROLE_ADMIN]);
        $today = $this->getJson('/api/card-reports/dashboard', $this->headers($admin))->assertOk()->json();

        $this->assertEquals(200, $today['totals']['expected_cash']);
        $this->assertSame(0, $today['totals']['cards_registered']);
        $this->assertEquals(1000, $today['reconciliation']['opening_liability']);
        $this->assertEquals(1200, $today['reconciliation']['closing_liability']);
        $this->assertTrue($today['reconciliation']['balanced']);

        $both = $this->getJson('/api/card-reports/dashboard?date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString(), $this->headers($admin))
            ->json();
        $this->assertEquals(1200, $both['totals']['expected_cash']);
    }

    public function test_card_cashier_only_sees_own_data_and_waiter_is_forbidden(): void
    {
        $this->scenario();

        $own = $this->getJson('/api/card-reports/dashboard?user_id='.$this->bruno->id, $this->headers($this->anna))->assertOk()->json();
        $this->assertCount(1, $own['per_cashier']);
        $this->assertSame('Anna', $own['per_cashier'][0]['name']);

        $brunoShift = CardShift::where('user_id', $this->bruno->id)->first();
        $this->getJson("/api/card-shifts/{$brunoShift->id}", $this->headers($this->anna))->assertForbidden();
        $this->getJson("/api/card-shifts/{$brunoShift->id}", $this->headers($this->bruno))
            ->assertOk()
            ->assertJsonPath('summary.opening_balance', 1000)
            ->assertJsonPath('summary.net_cash', -350)
            ->assertJsonPath('summary.expected_cash', 650);

        $shifts = $this->getJson('/api/card-shifts', $this->headers($this->anna))->assertOk()->json('data');
        $this->assertSame([$this->anna->id], collect($shifts)->pluck('user_id')->unique()->values()->all());

        $tx = $this->getJson('/api/card-transactions?type=topup,deposit_in', $this->headers($this->anna))->assertOk()->json('data');
        $this->assertCount(4, $tx);

        $waiter = User::factory()->create(['role_id' => User::ROLE_WAITER]);
        $this->getJson('/api/card-reports/dashboard', $this->headers($waiter))->assertForbidden();
    }

    public function test_sales_cash_register_ids_are_not_resolved_as_card_shifts(): void
    {
        $register = \App\Models\CashRegister::create([
            'user_id' => $this->anna->id, 'cash_register_status_id' => 2, 'opening_balance' => 0,
        ]);
        $admin = User::factory()->create(['role_id' => User::ROLE_ADMIN]);

        $this->getJson("/api/card-shifts/{$register->id}", $this->headers($admin))->assertNotFound();
    }
}
