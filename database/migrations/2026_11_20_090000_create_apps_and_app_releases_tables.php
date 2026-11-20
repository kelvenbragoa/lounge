<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('package_name')->nullable();
            $table->unsignedInteger('min_version_code')->default(0);
            $table->boolean('force_update')->default(false);
            $table->timestamps();
        });

        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('platform', 20)->default('android');
            $table->string('version_name', 40);
            $table->unsignedInteger('version_code');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('changelog')->nullable();
            $table->boolean('force_update')->default(false);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['app_id', 'platform', 'version_code']);
        });

        DB::table('apps')->insert([
            'slug' => 'livmobile',
            'name' => 'Liv Mobile',
            'description' => 'App dos garçons e do caixa de cartões.',
            'package_name' => 'mz.co.livmobile',
            'min_version_code' => 0,
            'force_update' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
        Schema::dropIfExists('apps');
    }
};
