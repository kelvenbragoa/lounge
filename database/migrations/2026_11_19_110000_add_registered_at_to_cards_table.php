<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            // Data do registo actual (um cartão devolvido pode voltar a ser registado).
            $table->timestamp('registered_at')->nullable()->after('registered_by');
            $table->index(['registered_by', 'registered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['registered_by', 'registered_at']);
            $table->dropColumn('registered_at');
        });
    }
};
