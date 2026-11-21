<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('payment_code', 60)->unique();
            $table->string('external_reference', 100)->unique()->nullable();
            $table->enum('gateway', ['sumopod_qris', 'manual_cash'])->default('sumopod_qris');
            $table->decimal('amount', 12, 2);
            $table->decimal('gateway_fee', 12, 2)->default(0.00);
            $table->decimal('net_amount', 12, 2)->default(0.00);
            $table->text('qris_string')->nullable();
            $table->text('payment_url')->nullable();
            $table->timestamp('qris_expires_at')->nullable()->index();
            $table->enum('status', ['pending', 'paid', 'failed', 'expired', 'cancelled'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'status', 'qris_expires_at'], 'idx_payments_invoice_status_exp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
