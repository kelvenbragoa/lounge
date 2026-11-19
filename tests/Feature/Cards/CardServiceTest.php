<?php

namespace Tests\Feature\Cards;

use App\Exceptions\CardException;
use App\Models\Card;
use App\Models\CardShift;
use App\Models\CardTransaction;
use App\Models\CashRegister;
use App\Models\User;
use App\Services\CardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CardService $service;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cards.deposit_amount' => 100, 'cards.replace_transfers_deposit' => true]);

        $this->service = app(CardService::class);
        $this->cashier = User::factory()->create(['role_id' => User::ROLE_CARD_CASHIER]);
        $this->service->openShift($this->cashier);
    }

    private function newCard(?string $uid = null): Card
    {
        return $this->service->register(['uid' => $uid ?? bin2hex(random_bytes(7)), 'holder_name' => 'Cliente'], $this->cashier);
    }

    private function topup(Card $card, $amount, string $tender = 'cash'): array
    {
        return $this->service->topup($card, $amount, $tender, $this->cashier, (string) Str::uuid());
    }

    private function assertLedgerMatches(Card $card): void
    {
        $card->refresh();
        $last = CardTransaction::where('card_id', $card->id)->orderByDesc('id')->first();

        $this->assertEquals($card->balance, $last?->balance_after ?? 0.0);
        $this->assertEquals($card->deposit_amount, $last?->deposit_after ?? 0.0);
        $this->assertEquals(
            round($card->balance + $card->deposit_amount, 2),
            round((float) CardTransaction::where('card_id', $card->id)->sum('amount'), 2)
        );
    }

    public function test_register_normalizes_uid_and_rejects_duplicates(): void
    {
        $card = $this->newCard('04:a1:b2:c3:d4');

        $this->assertSame('04A1B2C3D4', $card->uid);
        $this->assertSame(Card::STATUS_ACTIVE, $card->status);

        $this->expectException(CardException::class);
        $this->newCard('04A1B2C3D4');
    }

    public function test_first_topup_retains_deposit(): void
    {
        $card = $this->newCard();

        $result = $this->topup($card, 1000);

        $this->assertEquals(900, $result['card']->balance);
        $this->assertEquals(100, $result['card']->deposit_amount);
        $this->assertSame(
            [CardTransaction::TYPE_DEPOSIT_IN, CardTransaction::TYPE_TOPUP],
            $result['transactions']->pluck('type')->all()
        );
        $this->assertLedgerMatches($card);
    }

    public function test_first_topup_below_deposit_is_rejected(): void
    {
        $card = $this->newCard();

        try {
            $this->topup($card, 50);
            $this->fail('Esperava CardException');
        } catch (CardException $e) {
            $this->assertSame('min_first_topup', $e->errorCode);
        }

        $this->assertEquals(0, $card->fresh()->balance);
    }

    public function test_second_topup_does_not_retain_deposit_again(): void
    {
        $card = $this->newCard();
        $this->topup($card, 1000);

        $result = $this->topup($card, 250.50);

        $this->assertEquals(1150.50, $result['card']->balance);
        $this->assertEquals(100, $result['card']->deposit_amount);
        $this->assertCount(1, $result['transactions']);
        $this->assertLedgerMatches($card);
    }

    public function test_repeated_request_id_does_not_duplicate_topup(): void
    {
        $card = $this->newCard();
        $requestId = (string) Str::uuid();

        $this->service->topup($card, 1000, 'cash', $this->cashier, $requestId);
        $replay = $this->service->topup($card, 1000, 'cash', $this->cashier, $requestId);

        $this->assertTrue($replay['replayed']);
        $this->assertEquals(900, $card->fresh()->balance);
        $this->assertSame(2, CardTransaction::where('card_id', $card->id)->count());
    }

    public function test_request_id_cannot_be_reused_on_another_card(): void
    {
        $requestId = (string) Str::uuid();
        $this->service->topup($this->newCard(), 500, 'cash', $this->cashier, $requestId);

        $this->expectException(CardException::class);
        $this->service->topup($this->newCard(), 500, 'cash', $this->cashier, $requestId);
    }

    public function test_topup_requires_open_shift(): void
    {
        $other = User::factory()->create(['role_id' => User::ROLE_CARD_CASHIER]);
        $card = $this->newCard();

        try {
            $this->service->topup($card, 500, 'cash', $other, (string) Str::uuid());
            $this->fail('Esperava CardException');
        } catch (CardException $e) {
            $this->assertSame('card_shift_closed', $e->errorCode);
        }
    }

    public function test_charge_debits_and_rejects_insufficient_balance(): void
    {
        $card = $this->newCard();
        $this->topup($card, 1000);

        $this->service->charge($card, 350, $this->cashier, orderId: 10);
        $this->assertEquals(550, $card->fresh()->balance);

        try {
            $this->service->charge($card, 550.01, $this->cashier);
            $this->fail('Esperava CardException');
        } catch (CardException $e) {
            $this->assertSame('insufficient_balance', $e->errorCode);
        }

        $this->assertEquals(550, $card->fresh()->balance);
        $this->assertLedgerMatches($card);
    }

    public function test_charge_with_same_request_id_debits_once(): void
    {
        $card = $this->newCard();
        $this->topup($card, 1000);

        $first = $this->service->charge($card, 100, $this->cashier, requestId: 'sale-1');
        $second = $this->service->charge($card, 100, $this->cashier, requestId: 'sale-1');

        $this->assertSame($first->id, $second->id);
        $this->assertEquals(800, $card->fresh()->balance);
    }

    public function test_blocked_card_cannot_topup_or_charge(): void
    {
        $card = $this->newCard();
        $this->topup($card, 1000);
        $this->service->block($card, $this->cashier, false, 'teste');

        $this->expectException(CardException::class);
        $this->service->charge($card, 10, $this->cashier);
    }

    public function test_refund_returns_balance_and_deposit_and_card_can_be_reused(): void
    {
        $card = $this->newCard();
        $this->topup($card, 1000);
        $this->service->charge($card, 900, $this->cashier);

        $result = $this->service->refund($card, $this->cashier, (string) Str::uuid());

        $this->assertEquals(100, $result['total_paid']);
        $this->assertSame(Card::STATUS_RETURNED, $result['card']->status);
        $this->assertEquals(0, $result['card']->balance);
        $this->assertEquals(0, $result['card']->deposit_amount);
        $this->assertLedgerMatches($card);

        $reused = $this->service->register(['uid' => $card->uid, 'holder_name' => 'Outro cliente'], $this->cashier);
        $this->assertSame($card->id, $reused->id);
        $this->assertSame(Card::STATUS_ACTIVE, $reused->status);
        $this->assertSame('Outro cliente', $reused->holder_name);

        $again = $this->topup($reused, 500);
        $this->assertEquals(400, $again['card']->balance);
        $this->assertEquals(100, $again['card']->deposit_amount);
    }

    public function test_partial_and_full_reversal_limits(): void
    {
        $card = $this->newCard();
        $this->topup($card, 1000);
        $purchase = $this->service->charge($card, 300, $this->cashier);

        $this->service->reverse($purchase, $this->cashier, 100);
        $this->assertEquals(700, $card->fresh()->balance);

        try {
            $this->service->reverse($purchase, $this->cashier, 250);
            $this->fail('Esperava CardException');
        } catch (CardException $e) {
            $this->assertSame('invalid_reversal_amount', $e->errorCode);
        }

        $this->service->reverse($purchase, $this->cashier);
        $this->assertEquals(900, $card->fresh()->balance);
        $this->assertLedgerMatches($card);
    }

    public function test_reversal_on_returned_card_is_rejected(): void
    {
        $card = $this->newCard();
        $this->topup($card, 1000);
        $purchase = $this->service->charge($card, 300, $this->cashier);
        $this->service->refund($card, $this->cashier, (string) Str::uuid());

        $this->expectException(CardException::class);
        $this->service->reverse($purchase, $this->cashier);
    }

    public function test_replace_transfers_balance_and_deposit_and_reversal_goes_to_new_card(): void
    {
        $old = $this->newCard();
        $this->topup($old, 1000);
        $purchase = $this->service->charge($old, 200, $this->cashier);

        $result = $this->service->replace($old, 'NEWCARD01', $this->cashier, (string) Str::uuid());

        $this->assertEquals(0, $result['old_card']->balance);
        $this->assertEquals(0, $result['old_card']->deposit_amount);
        $this->assertSame(Card::STATUS_LOST, $result['old_card']->status);
        $this->assertSame($result['new_card']->id, $result['old_card']->replaced_by_card_id);
        $this->assertEquals(700, $result['new_card']->balance);
        $this->assertEquals(100, $result['new_card']->deposit_amount);
        $this->assertSame('Cliente', $result['new_card']->holder_name);

        $this->service->reverse($purchase, $this->cashier);
        $this->assertEquals(900, $result['new_card']->fresh()->balance);
        $this->assertLedgerMatches($old);
        $this->assertLedgerMatches($result['new_card']);
    }

    public function test_shift_summary_and_close_compute_expected_cash(): void
    {
        $a = $this->newCard();
        $b = $this->newCard();

        $this->topup($a, 1000, 'cash');   // 100 caução + 900 saldo, em dinheiro
        $this->topup($b, 500, 'mpesa');   // 100 caução + 400 saldo, M-Pesa
        $this->topup($a, 200, 'cash');
        $this->service->charge($a, 600, $this->cashier);
        $this->service->refund($a, $this->cashier, (string) Str::uuid()); // devolve 500 + 100 em dinheiro

        $result = $this->service->closeShift($this->cashier, 590);
        $summary = $result['summary'];

        $this->assertSame(2, $summary['cards_registered']);
        $this->assertSame(3, $summary['topups_count']);
        $this->assertEquals(1500, $summary['topups_total']);
        $this->assertEquals(200, $summary['deposits_received']);
        $this->assertSame(1, $summary['refunds_count']);
        $this->assertEquals(500, $summary['balance_refunded']);
        $this->assertEquals(100, $summary['deposits_refunded']);
        $this->assertEquals(500, $summary['by_tender']['mpesa']['received']);
        // Dinheiro: 1000 + 200 recebidos - 600 devolvidos = 600 esperados
        $this->assertEquals(600, $summary['expected_cash']);

        $this->assertSame(CardShift::STATUS_CLOSED, $result['shift']->cash_register_status_id);
        $this->assertEquals(600, $result['shift']->automatic_closing_balance);
        $this->assertEquals(10, $result['shift']->difference);
    }

    public function test_card_shift_is_invisible_to_sales_cash_register_queries(): void
    {
        $open = CashRegister::where('user_id', $this->cashier->id)
            ->where('cash_register_status_id', 1)
            ->first();

        $this->assertNull($open);
        $this->assertNotNull(CardShift::openFor($this->cashier->id));
    }

    public function test_transactions_cannot_be_edited_or_deleted(): void
    {
        $card = $this->newCard();
        $tx = $this->topup($card, 1000)['transactions']->last();

        $this->expectException(\LogicException::class);
        $tx->delete();
    }
}
