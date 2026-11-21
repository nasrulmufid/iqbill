<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->enum('template_type', [
                'invoice_created',
                'reminder_h7',
                'reminder_h3',
                'reminder_h1',
                'payment_receipt',
                'service_isolated',
                'service_restored',
            ]);
            $table->string('recipient_phone', 25);
            $table->text('message_body');
            $table->enum('delivery_status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('gateway_response')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notification_logs');
    }
};
