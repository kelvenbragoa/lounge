<?php

namespace Tests\Feature\Cards;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cards.deposit_amount' => 100]);
    }

    private function headersFor(int $roleId): array
    {
        $user = User::factory()->create(['role_id' => $roleId]);

        return [
            'token' => $user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    public function test_requests_without_token_are_rejected(): void
    {
        $this->getJson('/api/cards')->assertStatus(401);
    }

    public function test_waiter_can_lookup_but_cannot_manage_cards(): void
    {
        $cashier = $this->headersFor(User::ROLE_CARD_CASHIER);
        $waiter = $this->headersFor(User::ROLE_WAITER);

        $this->postJson('/api/cards', ['uid' => 'AA11BB22'], $cashier)->assertCreated();

        $this->postJson('/api/cards/lookup', ['uid' => 'aa:11:bb:22'], $waiter)
            ->assertOk()
            ->assertJsonPath('card.uid', 'AA11BB22');

        $this->postJson('/api/cards', ['uid' => 'CC33DD44'], $waiter)->assertForbidden();
        $this->getJson('/api/cards', $waiter)->assertForbidden();
        $this->postJson('/api/card-shift/open', [], $waiter)->assertForbidden();
    }

    public function test_full_cashier_flow(): void
    {
        $cashier = $this->headersFor(User::ROLE_CARD_CASHIER);

        $cardId = $this->postJson('/api/cards', ['uid' => '04:DE:AD:BE:EF', 'holder_name' => 'Maria'], $cashier)
            ->assertCreated()
            ->json('card.id');

        $this->postJson("/api/cards/{$cardId}/topup", [
            'amount' => 1000, 'tender_method' => 'cash', 'request_id' => (string) Str::uuid(),
        ], $cashier)
            ->assertStatus(409)
            ->assertJsonPath('code', 'card_shift_closed');

        $this->postJson('/api/card-shift/open', ['opening_balance' => 500], $cashier)
            ->assertOk()
            ->assertJsonPath('shift.opening_balance', 500);

        $requestId = (string) Str::uuid();
        $payload = ['amount' => 1000, 'tender_method' => 'cash', 'request_id' => $requestId];

        $this->postJson("/api/cards/{$cardId}/topup", $payload, $cashier)
            ->assertOk()
            ->assertJsonPath('card.balance', 900)
            ->assertJsonPath('deposit_retained', 100)
            ->assertJsonPath('replayed', false);

        $this->postJson("/api/cards/{$cardId}/topup", $payload, $cashier)
            ->assertOk()
            ->assertJsonPath('card.balance', 900)
            ->assertJsonPath('replayed', true);

        $this->postJson("/api/cards/{$cardId}/refund", ['request_id' => (string) Str::uuid()], $cashier)
            ->assertOk()
            ->assertJsonPath('total_paid', 1000)
            ->assertJsonPath('card.status', 'returned');

        $this->getJson('/api/card-shift/current', $cashier)
            ->assertOk()
            ->assertJsonPath('summary.expected_cash', 500);

        $this->postJson('/api/card-shift/close', ['closing_balance' => 480], $cashier)
            ->assertOk()
            ->assertJsonPath('shift.cash_register_status_id', 2)
            ->assertJsonPath('shift.difference', 20);
    }

    public function test_validation_errors_are_json(): void
    {
        $cashier = $this->headersFor(User::ROLE_CARD_CASHIER);
        $cardId = $this->postJson('/api/cards', ['uid' => 'FF001122'], $cashier)->json('card.id');

        $this->postJson("/api/cards/{$cardId}/topup", ['amount' => -5], $cashier)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'tender_method', 'request_id']);
    }

    public function test_lookup_of_unknown_card_returns_404(): void
    {
        $this->postJson('/api/cards/lookup', ['uid' => '99999999'], $this->headersFor(User::ROLE_WAITER))
            ->assertNotFound()
            ->assertJsonPath('code', 'card_not_found');
    }
}
