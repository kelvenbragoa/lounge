<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->integer('is_card')->default(0)->after('is_credit');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('card_id')->nullable()->after('customer_id');
            $table->index('card_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('card_id')->nullable()->after('customer_id');
            $table->index('card_id');
        });

        Schema::table('cash_registers', function (Blueprint $table) {
            $table->string('kind', 16)->default('sales')->after('user_id'); // sales | cards
            $table->index(['user_id', 'kind', 'cash_register_status_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'kind', 'cash_register_status_id']);
            $table->dropColumn('kind');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['card_id']);
            $table->dropColumn('card_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['card_id']);
            $table->dropColumn('card_id');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('is_card');
        });
    }
};
