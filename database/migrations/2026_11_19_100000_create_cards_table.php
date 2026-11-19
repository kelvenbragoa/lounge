<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 64)->unique(); // UID físico do chip NFC (hex, maiúsculas)
            $table->string('code', 32)->nullable()->unique(); // número impresso no cartão
            $table->string('holder_name')->nullable();
            $table->string('holder_phone', 32)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->string('status', 16)->default('active'); // active | blocked | lost | returned
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('replaced_by_card_id')->nullable()->constrained('cards')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
