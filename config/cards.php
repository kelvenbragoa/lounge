<?php

return [

    /*
    | Caução retida na primeira recarga e devolvida na devolução do cartão.
    | Ex.: cliente entrega 1000 MT na primeira recarga -> 100 MT caução, 900 MT saldo.
    */
    'deposit_amount' => (float) env('CARD_DEPOSIT_AMOUNT', 100),

    /*
    | true: vendas só aceitam "Cartão Lounge" (e consumo interno). Ligar quando web e app
    | já tiverem leitura de cartão no pagamento.
    */
    'exclusive_payment' => (bool) env('CARD_EXCLUSIVE_PAYMENT', false),

    /*
    | true: abrir uma mesa exige associar um cartão (o saldo passa a limitar o consumo da mesa).
    */
    'require_card_on_table_open' => (bool) env('CARD_REQUIRE_ON_TABLE_OPEN', false),

    'max_topup_amount' => (float) env('CARD_MAX_TOPUP_AMOUNT', 100000),

    /*
    | Formas de entrega de dinheiro aceites nas recargas.
    | Só 'cash' conta para o dinheiro físico esperado no fecho do caixa de cartões.
    */
    'tender_methods' => [
        'cash' => 'Dinheiro',
        'mpesa' => 'M-Pesa',
        'emola' => 'e-Mola',
        'mkesh' => 'mKesh',
        'pos' => 'POS (cartão bancário)',
    ],

    'physical_cash_tender_methods' => ['cash'],

    /*
    | Na substituição de um cartão perdido, a caução passa para o cartão novo (true)
    | ou fica retida no cartão perdido (false).
    */
    'replace_transfers_deposit' => (bool) env('CARD_REPLACE_TRANSFERS_DEPOSIT', true),

];
