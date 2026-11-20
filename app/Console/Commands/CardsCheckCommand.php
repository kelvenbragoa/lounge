<?php

namespace App\Console\Commands;

use App\Models\CardShift;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CardsCheckCommand extends Command
{
    protected $signature = 'cards:check';

    protected $description = 'Verifica se o sistema de cartões está pronto (schema, método de pagamento, role, config e ledger)';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        if (! $this->checkSchema()) {
            $this->newLine();
            $this->error('Schema incompleto: corra php artisan migrate antes de continuar.');

            return self::FAILURE;
        }

        $this->checkPaymentMethod();
        $this->checkRole();
        $this->checkConfig();
        $this->checkLedger();
        $this->checkShifts();

        $this->newLine();
        if ($this->failures > 0) {
            $this->error("{$this->failures} falha(s), {$this->warnings} aviso(s).");

            return self::FAILURE;
        }

        $this->info($this->warnings > 0 ? "Pronto, com {$this->warnings} aviso(s)." : 'Tudo pronto.');

        return self::SUCCESS;
    }

    private function checkSchema(): bool
    {
        $this->line('<comment>Schema</comment>');

        $required = [
            'cards' => ['uid', 'balance', 'deposit_amount', 'status', 'registered_at'],
            'card_transactions' => ['card_id', 'type', 'amount', 'balance_after', 'deposit_after', 'request_id'],
            'payment_methods' => ['is_card'],
            'payments' => ['card_id'],
            'orders' => ['card_id'],
            'cash_registers' => ['kind'],
        ];

        $ok = true;
        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->failCheck("Tabela {$table} não existe.");
                $ok = false;

                continue;
            }

            $missing = array_filter($columns, fn ($column) => ! Schema::hasColumn($table, $column));
            if ($missing) {
                $this->failCheck("{$table}: faltam colunas ".implode(', ', $missing).'.');
                $ok = false;
            }
        }

        if ($ok) {
            $this->pass('Tabelas e colunas dos cartões presentes.');
        }

        return $ok;
    }

    private function checkPaymentMethod(): void
    {
        $this->line('<comment>Método de pagamento</comment>');

        $methods = DB::table('payment_methods')->where('is_card', 1)->get(['id', 'name', 'status_id']);

        if ($methods->isEmpty()) {
            $this->failCheck('Nenhum método com is_card = 1 ("Cartão Lounge"). Corra: php artisan db:seed --class=PaymentMethodTableSeeder --force');

            return;
        }

        if ($methods->count() > 1) {
            $this->failCheck('Há '.$methods->count().' métodos com is_card = 1 ('.$methods->pluck('name')->implode(', ').'); deve haver só um.');

            return;
        }

        $method = $methods->first();
        $this->pass("\"{$method->name}\" (id {$method->id}).");

        if ((int) $method->status_id !== 1) {
            $this->warn("  ! O método está com status_id {$method->status_id} (esperado 1 = activo).");
            $this->warnings++;
        }
    }

    private function checkRole(): void
    {
        $this->line('<comment>Caixa de Cartões</comment>');

        $role = DB::table('roles')->where('id', User::ROLE_CARD_CASHIER)->first();
        if (! $role) {
            $this->failCheck('Role '.User::ROLE_CARD_CASHIER.' não existe. Corra: php artisan db:seed --class=RoleTableSeeder --force');

            return;
        }

        $this->pass("Role {$role->id} \"{$role->name}\".");

        $users = User::where('role_id', User::ROLE_CARD_CASHIER)->count();
        if ($users === 0) {
            $this->warn('  ! Nenhum utilizador com este role. Crie pelo menos um caixa de cartões.');
            $this->warnings++;
        } else {
            $this->pass("{$users} utilizador(es) caixa de cartões.");
        }
    }

    private function checkConfig(): void
    {
        $this->line('<comment>Configuração</comment>');

        $deposit = (float) config('cards.deposit_amount');
        if ($deposit <= 0) {
            $this->warn('  ! CARD_DEPOSIT_AMOUNT é 0: não será retida caução.');
            $this->warnings++;
        } else {
            $this->pass('Caução: '.number_format($deposit, 2).' MT.');
        }

        $this->pass('Recarga máxima: '.number_format((float) config('cards.max_topup_amount'), 2).' MT.');
        $this->pass('Substituição transfere caução: '.(config('cards.replace_transfers_deposit') ? 'sim' : 'não').'.');
        $this->pass('Cartão obrigatório ao abrir mesa: '.(config('cards.require_card_on_table_open') ? 'sim' : 'não').'.');

        if (config('cards.exclusive_payment')) {
            $this->pass('Pagamento exclusivo por cartão: LIGADO.');
        } else {
            $this->warn('  ! Pagamento exclusivo por cartão: desligado (CARD_EXCLUSIVE_PAYMENT=false). Os outros métodos continuam a aceitar vendas.');
            $this->warnings++;
        }
    }

    private function checkLedger(): void
    {
        $this->line('<comment>Ledger</comment>');

        $lastTransactions = DB::table('card_transactions')
            ->selectRaw('card_id, MAX(id) as last_id')
            ->groupBy('card_id');

        $mismatches = DB::table('cards')
            ->leftJoinSub($lastTransactions, 'last', 'last.card_id', '=', 'cards.id')
            ->leftJoin('card_transactions as t', 't.id', '=', 'last.last_id')
            ->where(function ($query) {
                $query->whereRaw('cards.balance <> COALESCE(t.balance_after, 0)')
                    ->orWhereRaw('cards.deposit_amount <> COALESCE(t.deposit_after, 0)');
            })
            ->get(['cards.id', 'cards.uid', 'cards.balance', 'cards.deposit_amount', 't.balance_after', 't.deposit_after']);

        $total = DB::table('cards')->count();

        if ($mismatches->isEmpty()) {
            $this->pass("{$total} cartão(ões) coerentes com o último movimento.");

            return;
        }

        $this->failCheck($mismatches->count().' cartão(ões) com saldo/caução diferente do ledger:');
        $this->table(
            ['id', 'uid', 'saldo', 'ledger saldo', 'caução', 'ledger caução'],
            $mismatches->map(fn ($row) => [
                $row->id, $row->uid, $row->balance, $row->balance_after ?? '-', $row->deposit_amount, $row->deposit_after ?? '-',
            ])->all()
        );
    }

    private function checkShifts(): void
    {
        $this->line('<comment>Turnos de cartões</comment>');

        $open = CardShift::with('user:id,name')
            ->where('cash_register_status_id', CardShift::STATUS_OPEN)
            ->get();

        if ($open->isEmpty()) {
            $this->pass('Nenhum turno aberto.');

            return;
        }

        foreach ($open as $shift) {
            $this->line("  - Turno #{$shift->id} aberto por ".($shift->user->name ?? "utilizador {$shift->user_id}")
                .' desde '.optional($shift->opened_at)->format('d/m/Y H:i'));
        }
    }

    private function pass(string $message): void
    {
        $this->line("  <info>✓</info> {$message}");
    }

    private function failCheck(string $message): void
    {
        $this->line("  <error>✗</error> {$message}");
        $this->failures++;
    }
}
