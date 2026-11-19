<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Livro-razão: linhas nunca são editadas nem apagadas; correcções entram como novas linhas.
        Schema::create('card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('cards');
            $table->string('type', 32); // deposit_in, topup, purchase, purchase_reversal, refund_balance, deposit_out, transfer_in, transfer_out, adjustment
            $table->decimal('amount', 12, 2); // positivo entra no cartão, negativo sai
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->decimal('deposit_before', 12, 2)->default(0);
            $table->decimal('deposit_after', 12, 2)->default(0);
            $table->string('tender_method', 32)->nullable(); // cash | mpesa | emola | ... (dinheiro físico/electrónico movimentado)
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('cash_register_id')->nullable();
            $table->unsignedBigInteger('related_transaction_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_id', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['type', 'request_id']);
            $table->index(['card_id', 'created_at']);
            $table->index(['cash_register_id', 'type']);
            $table->index(['user_id', 'created_at']);
            $table->index('order_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_transactions');
    }
};
