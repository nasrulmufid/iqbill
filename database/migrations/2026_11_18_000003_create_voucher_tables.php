<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('mikrotik_router_id')->constrained('mikrotik_routers')->cascadeOnDelete();
            $table->string('profile_name', 80);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('validity_label', 60)->default('1 Hari');
            $table->string('uptime_limit', 30)->nullable();
            $table->unsignedInteger('data_limit_mb')->nullable();
            $table->unsignedTinyInteger('code_length')->default(6);
            $table->string('code_prefix', 15)->nullable();
            $table->enum('charset', ['alnum_upper', 'numbers', 'letters_upper'])->default('alnum_upper');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('voucher_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code', 40)->unique();
            $table->foreignId('voucher_template_id')->constrained('voucher_templates')->cascadeOnDelete();
            $table->foreignId('mikrotik_router_id')->constrained('mikrotik_routers')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedSmallInteger('synced_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_batch_id')->constrained('voucher_batches')->cascadeOnDelete();
            $table->string('username', 40)->unique();
            $table->string('password', 40);
            $table->string('profile_name', 80);
            $table->decimal('price', 12, 2)->default(0);
            $table->enum('status', ['generated', 'synced', 'used', 'expired'])->default('generated');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['voucher_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('voucher_batches');
        Schema::dropIfExists('voucher_templates');
    }
};
