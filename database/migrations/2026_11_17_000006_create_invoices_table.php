<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 40)->unique();
            $table->string('public_token', 64)->unique();
            $table->foreignId('subscription_id')->constrained('subscriptions');
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('gateway_fee_charged', 12, 2)->default(0.00);
            $table->decimal('total_amount', 12, 2);
            $table->unsignedSmallInteger('payment_attempt_count')->default(0);
            $table->enum('status', ['unpaid', 'paid', 'overdue', 'cancelled'])->default('unpaid');
            $table->date('due_date')->index();
            $table->timestamp('paid_at')->nullable();
            $table->string('cancelled_reason', 180)->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'billing_period_start'], 'uq_invoices_sub_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
