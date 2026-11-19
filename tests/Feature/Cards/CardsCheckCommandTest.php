<?php

namespace Tests\Feature\Cards;

use App\Models\User;
use App\Services\CardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardsCheckCommandTest extends TestCase
{
    use DatabaseTransactions;

    private function cardWithTopup(): int
    {
        config(['cards.deposit_amount' => 100]);

        $service = app(CardService::class);
        $cashier = User::factory()->create(['role_id' => User::ROLE_CARD_CASHIER]);
        $service->openShift($cashier);

        $card = $service->register(['uid' => bin2hex(random_bytes(7))], $cashier);
        $service->topup($card, 1000, 'cash', $cashier, (string) Str::uuid());

        return $card->id;
    }

    public function test_passes_when_cards_match_the_ledger(): void
    {
        $this->cardWithTopup();

        $this->artisan('cards:check')
            ->expectsOutputToContain('coerentes com o último movimento')
            ->assertExitCode(0);
    }

    public function test_fails_when_a_card_balance_diverges_from_the_ledger(): void
    {
        $cardId = $this->cardWithTopup();
        DB::table('cards')->where('id', $cardId)->update(['balance' => 5000]);

        $this->artisan('cards:check')
            ->expectsOutputToContain('diferente do ledger')
            ->assertExitCode(1);
    }

    public function test_fails_when_more_than_one_card_payment_method_exists(): void
    {
        DB::table('payment_methods')->insert([
            'name' => 'Cartão Duplicado',
            'status_id' => 1,
            'counts_in_revenue' => 1,
            'is_credit' => 0,
            'is_card' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cards:check')
            ->expectsOutputToContain('deve haver só um')
            ->assertExitCode(1);
    }
}
