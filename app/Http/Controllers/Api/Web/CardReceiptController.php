<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\CardTransaction;
use App\Services\CardReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CardReceiptController extends Controller
{
    public function __construct(private readonly CardReceiptService $receipts)
    {
    }

    /**
     * PDF do recibo de um movimento de cartão.
     * Admin, Supervisor e Caixa de Cartões imprimem qualquer recibo; os restantes
     * perfis só os das compras que eles próprios cobraram.
     */
    public function show(Request $request, CardTransaction $transaction)
    {
        $user = Auth::user();

        $isStaff = in_array((int) $user->role_id, [1, 2, 8], true);
        $isOwnPurchase = $transaction->type === CardTransaction::TYPE_PURCHASE
            && (int) $transaction->user_id === (int) $user->id;

        abort_unless($isStaff || $isOwnPurchase, 403, 'Sem permissão para imprimir este recibo.');

        return $this->receipts->pdf($transaction, $request->boolean('copy'));
    }
}
