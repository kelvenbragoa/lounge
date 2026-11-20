<?php

namespace Tests\Feature\Cards;

use App\Models\Card;
use App\Models\CardTransaction;
use App\Models\CashRegister;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\CardReceiptService;
use App\Services\CardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardPaymentFlowTest extends TestCase
{
    use DatabaseTransactions;

    private array $headers;
    private Card $card;
    private int $productId;
    private int $tableId;
    private int $cardMethodId;
    private int $stockCenterId;
    private int $waiterId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cards.deposit_amount' => 100,
            'cards.exclusive_payment' => false,
            'cards.require_card_on_table_open' => false,
        ]);

        $service = app(CardService::class);
        $cashier = User::factory()->create(['role_id' => User::ROLE_CARD_CASHIER]);
        $service->openShift($cashier);
        $this->card = $service->register(['uid' => 'CAFE'.bin2hex(random_bytes(4))], $cashier);
        $service->topup($this->card, 1000, 'cash', $cashier, (string) Str::uuid()); // saldo 900

        $waiter = User::factory()->create(['role_id' => User::ROLE_WAITER]);
        $this->waiterId = $waiter->id;
        CashRegister::create([
            'user_id' => $waiter->id,
            'cash_register_status_id' => 1,
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);
        $this->headers = [
            'token' => $waiter->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $this->stockCenterId = DB::table('stock_centers')->where('is_principal_stock', 1)->value('id')
            ?? DB::table('stock_centers')->insertGetId([
                'is_principal_stock' => 1, 'name' => 'Principal', 'code' => 'P', 'location' => '-',
                'maximum_capacity' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

        $categoryId = DB::table('categories')->insertGetId(['department_id' => 2, 'name' => 'Teste', 'created_at' => now(), 'updated_at' => now()]);
        $subCategoryId = DB::table('sub_categories')->insertGetId(['name' => 'Teste', 'category_id' => $categoryId, 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = DB::table('products')->insertGetId([
            'name' => 'Cerveja', 'department_id' => 2, 'category_id' => $categoryId, 'sub_category_id' => $subCategoryId,
            'price' => 150, 'buy_price' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_center_products')->insert([
            'product_id' => $this->productId, 'stock_center_id' => $this->stockCenterId, 'quantity' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->tableId = DB::table('tables')->insertGetId([
            'name' => 'Mesa teste', 'capacity' => 4, 'table_status_id' => 1, 'monthly_limit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->cardMethodId = PaymentMethod::card()->id;
    }

    private function stock(): int
    {
        return (int) DB::table('stock_center_products')
            ->where('product_id', $this->productId)->where('stock_center_id', $this->stockCenterId)->value('quantity');
    }

    private function quicksell(int $quantity, array $overrides = [])
    {
        return $this->postJson('/api/pdv/quicksell', $overrides + [
            'payment_method_id' => $this->cardMethodId,
            'products' => [['id' => $this->productId, 'quantity' => $quantity]],
            'total' => 150 * $quantity,
            'card_uid' => $this->card->uid,
        ], $this->headers);
    }

    public function test_web_quicksell_charges_card(): void
    {
        $response = $this->quicksell(2)->assertOk()->assertJsonPath('card_balance', 600);

        $payment = Payment::find($response->json('payment_id'));
        $this->assertSame($this->card->id, $payment->card_id);
        $this->assertSame($this->card->id, Order::find($response->json('order_id'))->card_id);
        $this->assertSame(98, $this->stock());
        $this->assertSame(1, CardTransaction::where('order_id', $payment->order_id)->where('type', 'purchase')->count());
    }

    public function test_quicksell_with_insufficient_balance_creates_nothing(): void
    {
        $orders = Order::count();

        $this->quicksell(7) // 1050 > 900
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_balance');

        $this->assertSame($orders, Order::count());
        $this->assertSame(100, $this->stock());
        $this->assertEquals(900, $this->card->fresh()->balance);
    }

    public function test_quicksell_rejects_tampered_total_when_paying_by_card(): void
    {
        $this->quicksell(2, ['total' => 10])->assertStatus(403);

        $this->assertEquals(900, $this->card->fresh()->balance);
        $this->assertSame(100, $this->stock());
    }

    public function test_cash_quicksell_still_works_when_not_exclusive(): void
    {
        $cash = PaymentMethod::where('is_card', 0)->where('counts_in_revenue', 1)->where('is_credit', 0)->first();

        $response = $this->quicksell(2, ['payment_method_id' => $cash->id, 'card_uid' => null, 'amount_tendered' => 500])
            ->assertOk();

        $order = Order::find($response->json('order_id'));
        $this->assertEquals(200, $order->change_amount);
        $this->assertNull($order->card_id);
        $this->assertEquals(900, $this->card->fresh()->balance);
    }

    public function test_card_method_requires_card(): void
    {
        $this->quicksell(1, ['card_uid' => null])
            ->assertStatus(422)
            ->assertJsonPath('code', 'card_required');
    }

    private function internalMethod(): PaymentMethod
    {
        return PaymentMethod::where('is_card', 0)->where('counts_in_revenue', 0)->where('is_credit', 0)->first()
            ?? PaymentMethod::create([
                'name' => 'Consumo Interno', 'status_id' => 1,
                'counts_in_revenue' => 0, 'is_credit' => 0, 'is_card' => 0,
            ]);
    }

    private function headersForRole(int $roleId, bool $openRegister = true): array
    {
        $user = User::factory()->create(['role_id' => $roleId]);

        if ($openRegister) {
            CashRegister::create([
                'user_id' => $user->id, 'cash_register_status_id' => 1, 'opening_balance' => 0, 'opened_at' => now(),
            ]);
        }

        return ['token' => $user->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_exclusive_mode_blocks_cash_and_hides_internal_consumption_from_waiters(): void
    {
        config(['cards.exclusive_payment' => true]);

        $cash = PaymentMethod::where('is_card', 0)->where('counts_in_revenue', 1)->where('is_credit', 0)->first();
        $internal = $this->internalMethod();

        $this->quicksell(1, ['payment_method_id' => $cash->id, 'card_uid' => null])
            ->assertStatus(422)
            ->assertJsonPath('code', 'card_payment_required');

        $this->quicksell(1, ['payment_method_id' => $internal->id, 'card_uid' => null])
            ->assertStatus(403)
            ->assertJsonPath('code', 'internal_consumption_forbidden');
        $this->assertSame(100, $this->stock());

        $this->getJson('/api/pdv/quicksell', $this->headers)
            ->assertOk()
            ->assertJsonMissing(['id' => $cash->id, 'name' => $cash->name])
            ->assertJsonMissing(['id' => $internal->id, 'name' => $internal->name]);

        $this->getJson('/api/mobile-payment-methods', $this->headers)
            ->assertOk()
            ->assertJsonMissing(['id' => $internal->id, 'name' => $internal->name]);
    }

    public function test_exclusive_mode_lets_supervisor_register_internal_consumption(): void
    {
        config(['cards.exclusive_payment' => true]);
        $internal = $this->internalMethod();
        $supervisor = $this->headersForRole(User::ROLE_SUPERVISOR);

        $this->postJson('/api/pdv/quicksell', [
            'payment_method_id' => $internal->id,
            'products' => [['id' => $this->productId, 'quantity' => 1]],
            'total' => 150,
        ], $supervisor)->assertOk();

        $this->assertSame(99, $this->stock());

        $this->getJson('/api/pdv/quicksell', $supervisor)
            ->assertOk()
            ->assertJsonFragment(['id' => $internal->id]);
    }

    public function test_internal_consumption_roles_are_configurable(): void
    {
        config(['cards.exclusive_payment' => true, 'cards.internal_consumption_roles' => [User::ROLE_ADMIN]]);
        $internal = $this->internalMethod();

        $this->postJson('/api/pdv/quicksell', [
            'payment_method_id' => $internal->id,
            'products' => [['id' => $this->productId, 'quantity' => 1]],
            'total' => 150,
        ], $this->headersForRole(User::ROLE_SUPERVISOR))->assertStatus(403);

        $this->postJson('/api/pdv/quicksell', [
            'payment_method_id' => $internal->id,
            'products' => [['id' => $this->productId, 'quantity' => 1]],
            'total' => 150,
        ], $this->headersForRole(User::ROLE_ADMIN))->assertOk();
    }

    public function test_table_flow_with_card_limits_consumption_and_pays_with_linked_card(): void
    {
        $this->postJson('/api/pdv', [
            'table_id' => $this->tableId,
            'products' => [['id' => $this->productId, 'quantity' => 4]],
            'total' => 600,
            'card_uid' => $this->card->uid,
        ], $this->headers)->assertOk();

        $order = Order::where('table_id', $this->tableId)->where('order_status_id', 1)->first();
        $this->assertSame($this->card->id, $order->card_id);

        // 600 + 450 = 1050 > 900: recusado e nada muda
        $this->postJson('/api/pdv', [
            'table_id' => $this->tableId,
            'products' => [['id' => $this->productId, 'quantity' => 3]],
            'total' => 450,
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_balance');

        $this->assertEquals(600, $order->fresh()->total);
        $this->assertSame(96, $this->stock());

        $this->getJson("/api/pdv/closeaccount/{$this->tableId}", $this->headers)->assertOk();

        $this->postJson('/api/payaccount', [
            'table_id' => $this->tableId,
            'payment_method_id' => $this->cardMethodId,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('card_balance', 300);

        $this->assertSame(3, $order->fresh()->order_status_id);
        $this->assertSame(1, (int) DB::table('tables')->where('id', $this->tableId)->value('table_status_id'));
    }

    public function test_failed_card_payment_leaves_table_account_untouched(): void
    {
        $this->postJson('/api/pdv', [
            'table_id' => $this->tableId,
            'products' => [['id' => $this->productId, 'quantity' => 2]],
            'total' => 300,
        ], $this->headers)->assertOk();
        $this->getJson("/api/pdv/closeaccount/{$this->tableId}", $this->headers)->assertOk();

        $poor = app(CardService::class)->register(['uid' => 'BAD0'.bin2hex(random_bytes(4))], User::factory()->create(['role_id' => 8]));

        $this->postJson('/api/payaccount', [
            'table_id' => $this->tableId,
            'payment_method_id' => $this->cardMethodId,
            'card_uid' => $poor->uid,
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_balance');

        $order = Order::where('table_id', $this->tableId)->latest('id')->first();
        $this->assertSame(2, $order->order_status_id);
        $this->assertSame(0, Payment::where('order_id', $order->id)->count());
        $this->assertSame(5, (int) DB::table('tables')->where('id', $this->tableId)->value('table_status_id'));
    }

    public function test_deleting_item_and_order_returns_money_to_card(): void
    {
        $response = $this->quicksell(3)->assertOk(); // 450 -> saldo 450
        $orderId = $response->json('order_id');
        $item = OrderItem::where('order_id', $orderId)->first();

        $this->postJson("/api/orderitem/{$item->id}", [], $this->headers)->assertOk();
        $this->assertEquals(900, $this->card->fresh()->balance);

        $second = $this->quicksell(2)->assertOk()->json('order_id'); // 300 -> saldo 600
        $this->postJson("/api/quickorderdelete/{$second}", [], $this->headers)->assertNoContent();
        $this->assertEquals(900, $this->card->fresh()->balance);

        $this->assertSame(
            2,
            CardTransaction::where('card_id', $this->card->id)->where('type', CardTransaction::TYPE_PURCHASE_REVERSAL)->count()
        );
    }

    public function test_mobile_table_opened_without_card_is_paid_with_card_read_at_the_end(): void
    {
        $this->postJson('/api/createorder', [
            'user_id' => $this->waiterId, 'table_id' => $this->tableId, 'product_id' => $this->productId, 'quantity' => 2,
        ], $this->headers)->assertOk();

        $order = Order::where('table_id', $this->tableId)->where('order_status_id', 1)->firstOrFail();
        $this->assertNull($order->card_id);

        $this->getJson("/api/closeaccount/{$this->tableId}", $this->headers)->assertOk();

        $requestId = (string) Str::uuid();
        $payload = [
            'table_id' => $this->tableId,
            'payment_method_id' => $this->cardMethodId,
            'card_uid' => $this->card->uid,
            'request_id' => $requestId,
        ];
        $this->postJson('/api/payaccount', $payload, $this->headers)
            ->assertOk()
            ->assertJsonPath('card_balance', 600);
        $this->postJson('/api/payaccount', $payload, $this->headers)->assertOk();

        $this->assertEquals(600, $this->card->fresh()->balance);
        $this->assertSame(3, $order->fresh()->order_status_id);
        $this->assertSame($this->card->id, Payment::where('order_id', $order->id)->sole()->card_id);
    }

    public function test_table_linked_to_one_card_can_be_paid_with_another(): void
    {
        $this->postJson('/api/pdv', [
            'table_id' => $this->tableId,
            'products' => [['id' => $this->productId, 'quantity' => 2]],
            'total' => 300,
            'card_uid' => $this->card->uid,
        ], $this->headers)->assertOk();
        $this->getJson("/api/pdv/closeaccount/{$this->tableId}", $this->headers)->assertOk();

        $service = app(CardService::class);
        $cashier = User::factory()->create(['role_id' => User::ROLE_CARD_CASHIER]);
        $service->openShift($cashier);
        $other = $service->register(['uid' => 'D0D0'.bin2hex(random_bytes(4))], $cashier);
        $service->topup($other, 500, 'cash', $cashier, (string) Str::uuid()); // saldo 400

        $this->postJson('/api/payaccount', [
            'table_id' => $this->tableId,
            'payment_method_id' => $this->cardMethodId,
            'card_uid' => $other->uid,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('card_balance', 100);

        $this->assertEquals(900, $this->card->fresh()->balance);
    }

    public function test_mobile_payment_methods_expose_card_method(): void
    {
        $this->getJson('/api/mobile-payment-methods', $this->headers)
            ->assertOk()
            ->assertJsonPath('card_payment_method_id', $this->cardMethodId)
            ->assertJsonPath('exclusive', false);
    }

    public function test_mobile_quicksell_with_card_is_idempotent(): void
    {
        $payload = [
            'payment_method_id' => (string) $this->cardMethodId,
            'products' => [['id' => $this->productId, 'quantity' => 1]],
            'total' => 150,
            'card_uid' => $this->card->uid,
            'request_id' => (string) Str::uuid(),
        ];

        $this->postJson('/api/mobile-savequicksell', $payload, $this->headers)->assertOk()->assertJsonPath('card_balance', 750);
        $this->postJson('/api/mobile-savequicksell', $payload, $this->headers)->assertOk();

        $this->assertEquals(750, $this->card->fresh()->balance);
        $this->assertSame(99, $this->stock());
    }

    public function test_mobile_close_account_without_open_order_returns_404(): void
    {
        $this->getJson("/api/closeaccount/{$this->tableId}", $this->headers)
            ->assertNotFound()
            ->assertJsonPath('message', 'Esta mesa não tem conta aberta.');
    }

    public function test_mobile_close_account_refuses_table_opened_by_another_waiter(): void
    {
        $owner = User::factory()->create(['role_id' => User::ROLE_WAITER]);
        Order::create([
            'table_id' => $this->tableId,
            'user_id' => $owner->id,
            'total' => 0,
            'order_status_id' => 1,
        ]);

        $this->getJson("/api/closeaccount/{$this->tableId}", $this->headers)
            ->assertStatus(400)
            ->assertJsonPath('message', 'Não é possível fechar a conta desta mesa. Foi aberta por '.$owner->name.'.');

        $this->assertSame(1, Order::where('table_id', $this->tableId)->where('order_status_id', 1)->count());
    }

    public function test_card_payment_response_carries_receipt_data(): void
    {
        $response = $this->quicksell(2)->assertOk();

        $response->assertJsonPath('card_balance_before', 900)
            ->assertJsonPath('card_balance_after', 600)
            ->assertJsonPath('card_uid_masked', '****'.substr($this->card->uid, -4));

        $transaction = CardTransaction::find($response->json('card_transaction_id'));
        $this->assertSame('purchase', $transaction->type);
        $this->assertSame((int) $response->json('order_id'), (int) $transaction->order_id);
    }

    public function test_mobile_quicksell_returns_order_and_receipt_data(): void
    {
        $this->postJson('/api/mobile-savequicksell', [
            'payment_method_id' => (string) $this->cardMethodId,
            'products' => [['id' => $this->productId, 'quantity' => 1]],
            'total' => 150,
            'card_uid' => $this->card->uid,
            'request_id' => (string) Str::uuid(),
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('card_balance_before', 900)
            ->assertJsonPath('card_balance_after', 750)
            ->assertJsonStructure(['order_id', 'card_transaction_id', 'card_uid_masked']);
    }

    public function test_lookup_statement_lists_products_and_masks_uid(): void
    {
        $this->quicksell(2)->assertOk();

        $response = $this->postJson('/api/cards/lookup', ['uid' => $this->card->uid], $this->headers)->assertOk();

        $response->assertJsonPath('card.uid_masked', '****'.substr($this->card->uid, -4));

        $purchase = collect($response->json('transactions'))->firstWhere('type', 'purchase');
        $this->assertEquals(600, $purchase['balance_after']);
        $this->assertSame('Cerveja', $purchase['items'][0]['product']['name']);
        $this->assertSame(2, (int) $purchase['items'][0]['quantity']);
    }

    public function test_receipt_pdf_permissions(): void
    {
        $id = $this->quicksell(1)->assertOk()->json('card_transaction_id');

        // o garçom que cobrou imprime o seu recibo
        $own = $this->get("/api/cards/transactions/{$id}/receipt", $this->headers)->assertOk();
        $this->assertStringContainsString('application/pdf', $own->headers->get('content-type'));

        // outro garçom não
        $other = User::factory()->create(['role_id' => User::ROLE_WAITER]);
        $this->get("/api/cards/transactions/{$id}/receipt?copy=1", [
            'token' => $other->createToken('t')->plainTextToken, 'Accept' => 'application/json',
        ])->assertForbidden();

        // caixa de cartões imprime qualquer um (2ª via)
        $cashier = User::factory()->create(['role_id' => User::ROLE_CARD_CASHIER]);
        $this->get("/api/cards/transactions/{$id}/receipt?copy=1", [
            'token' => $cashier->createToken('t')->plainTextToken, 'Accept' => 'application/json',
        ])->assertOk();
    }

    public function test_replace_response_exposes_transfer_for_receipt(): void
    {
        $cashier = User::factory()->create(['role_id' => User::ROLE_CARD_CASHIER]);
        app(CardService::class)->openShift($cashier);
        $headers = ['token' => $cashier->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $response = $this->postJson("/api/cards/{$this->card->id}/replace", [
            'new_uid' => 'FACE'.bin2hex(random_bytes(4)),
            'request_id' => (string) Str::uuid(),
        ], $headers)->assertOk();

        $transferId = $response->json('transactions.0.id');
        $this->assertSame('transfer_in', $response->json('transactions.0.type'));

        $data = app(CardReceiptService::class)->data(CardTransaction::find($transferId));
        $this->assertSame('transfer_in', $data['kind']);
        $this->assertSame('900,00 MT', $data['balance_after']);
        $this->get("/api/cards/transactions/{$transferId}/receipt", $headers)->assertOk();
    }

    public function test_receipt_data_for_topup_and_refund(): void
    {
        $service = app(CardService::class);
        $receipts = app(CardReceiptService::class);
        $cashier = User::factory()->create(['role_id' => User::ROLE_CARD_CASHIER]);
        $service->openShift($cashier);

        $card = $service->register(['uid' => 'BEEF'.bin2hex(random_bytes(4)), 'holder_name' => 'Maria Joao'], $cashier);
        $topup = $service->topup($card, 500, 'mpesa', $cashier, (string) Str::uuid());

        $data = $receipts->data($topup['transactions']->first());
        $this->assertSame('topup', $data['kind']);
        $this->assertSame('****'.substr($card->uid, -4), $data['card_masked']);
        $this->assertSame('Maria Joao', $data['holder_name']);
        $this->assertSame('0,00 MT', $data['balance_before']);
        $this->assertSame('400,00 MT', $data['balance_after']);
        $this->assertContains(['Valor recebido', '500,00 MT', true], $data['rows']);
        $this->assertContains(['Caução retida', '100,00 MT'], $data['rows']);

        $refund = $service->refund($card->fresh(), $cashier, (string) Str::uuid());
        $data = $receipts->data($refund['transactions']->first());
        $this->assertSame('refund', $data['kind']);
        $this->assertSame('400,00 MT', $data['balance_before']);
        $this->assertSame('0,00 MT', $data['balance_after']);
        $this->assertContains(['Total entregue', '500,00 MT', true], $data['rows']);
    }
}
