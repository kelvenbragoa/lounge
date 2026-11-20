<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Recibo {{ $number }}</title>
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier', monospace; /* letra de talão de banco/ATM */
            font-size: 11px;
            color: #000;
        }
        .r { padding: 12px 12px 8px 12px; }
        .c { text-align: center; }
        .b { font-weight: bold; }
        .big { font-size: 15px; letter-spacing: 1px; }
        .sep { border-top: 1px dashed #000; margin: 7px 0; height: 0; }
        .sep-solid { border-top: 1px solid #000; margin: 7px 0; height: 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1.5px 0; }
        td.v { text-align: right; }
        .items td { padding: 2px 0; }
        .items .q { width: 22px; }
        .items .t { text-align: right; width: 78px; }
        .items .sub { font-size: 9px; padding-top: 0; }
        .total td { font-size: 13px; font-weight: bold; padding-top: 3px; }
        .small { font-size: 9px; }
        .copy { border: 1px solid #000; padding: 2px 0; margin-top: 4px; }
    </style>
</head>
<body>
@php
    $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
@endphp
<div class="r">
    <div class="c b big">LIV BEIRA</div>
    <div class="c">Maquinino, Beira</div>
    <div class="c">Tel: +258 84 000 000</div>

    <div class="sep"></div>

    <div class="c b">{{ $title }}</div>
    @if($copy)
        <div class="c b copy">*** 2ª VIA ***</div>
    @endif

    <div class="sep"></div>

    <table>
        <tr><td>Recibo Nº</td><td class="v">{{ $number }}</td></tr>
        <tr><td>Data</td><td class="v">{{ $date->format('d/m/Y H:i') }}</td></tr>
        <tr><td>Operador</td><td class="v">{{ $operator }}</td></tr>
        <tr><td>Cartão</td><td class="v b">{{ $card_masked }}</td></tr>
        @if($holder_name)
            <tr><td>Titular</td><td class="v">{{ $holder_name }}</td></tr>
        @endif
    </table>

    @if(count($items))
        <div class="sep"></div>
        <table class="items">
            @foreach($items as $item)
                <tr>
                    <td class="q">{{ $item['quantity'] }}x</td>
                    <td>{{ $item['name'] }}</td>
                    <td class="t">{{ $fmt($item['total']) }}</td>
                </tr>
                @if($item['quantity'] > 1)
                    <tr>
                        <td></td>
                        <td class="sub" colspan="2">@ {{ $fmt($item['price']) }} MT</td>
                    </tr>
                @endif
            @endforeach
        </table>
    @endif

    <div class="sep"></div>

    <table>
        @foreach($rows as $row)
            <tr class="{{ ($row[2] ?? false) ? 'b' : '' }}">
                <td>{{ $row[0] }}</td>
                <td class="v">{{ $row[1] }}</td>
            </tr>
        @endforeach
    </table>

    @if($notes)
        <div class="small">Nota: {{ $notes }}</div>
    @endif

    <div class="sep-solid"></div>

    <table>
        <tr><td>Saldo anterior</td><td class="v">{{ $balance_before }}</td></tr>
        <tr class="total"><td>Saldo atual</td><td class="v">{{ $balance_after }}</td></tr>
        @if($deposit_after)
            <tr class="small"><td>Caução retida</td><td class="v">{{ $deposit_after }}</td></tr>
        @endif
    </table>

    <div class="sep"></div>

    <div class="c small">Guarde este recibo.</div>
    <div class="c small">Obrigado pela preferência!</div>
</div>
</body>
</html>
