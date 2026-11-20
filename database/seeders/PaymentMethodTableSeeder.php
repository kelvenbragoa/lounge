<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Métodos de pagamento. Pode correr-se várias vezes: cada método é identificado pelo
 * nome (updateOrInsert), por isso não duplica.
 *
 * Flags:
 *  - counts_in_revenue: dinheiro realmente recebido (entra na receita)
 *  - is_credit:         venda a crédito (cobrada mais tarde)
 *  - is_card:           cartão pré-pago do lounge (debita o saldo do cartão)
 *  - sem nenhuma:       consumo interno
 *
 * ATENÇÃO À ORDEM: o código assume que "Credito" é o id 8 (PdvController,
 * TableMobileController e as páginas do PDV). Numa base de dados vazia a ordem abaixo
 * garante isso; não reordene nem insira métodos antes dele.
 *
 * Ao voltar a correr, repõe as flags (counts_in_revenue, is_credit, is_card) dos métodos
 * existentes; o estado (status_id) não é alterado.
 */
class PaymentMethodTableSeeder extends Seeder
{
    private const METHODS = [
        // nome               counts_in_revenue, is_credit, is_card
        'Dinheiro'        => [1, 0, 0],
        'Cartão'          => [1, 0, 0], // cartão bancário (POS)
        'Emola'           => [1, 0, 0],
        'MPesa'           => [1, 0, 0],
        'Mkesh'           => [1, 0, 0],
        'Conta Movel'     => [1, 0, 0],
        'Consumo Interno' => [0, 0, 0],
        'Credito'         => [0, 1, 0],
        'Cartão Lounge'   => [1, 0, 1],
    ];

    public function run(): void
    {
        foreach (self::METHODS as $name => [$countsInRevenue, $isCredit, $isCard]) {
            $flags = [
                'counts_in_revenue' => $countsInRevenue,
                'is_credit' => $isCredit,
                'is_card' => $isCard,
                'updated_at' => now(),
            ];

            if (DB::table('payment_methods')->where('name', $name)->exists()) {
                DB::table('payment_methods')->where('name', $name)->limit(1)->update($flags);

                continue;
            }

            DB::table('payment_methods')->insert($flags + [
                'name' => $name,
                'status_id' => 1,
                'created_at' => now(),
            ]);
        }
    }
}
