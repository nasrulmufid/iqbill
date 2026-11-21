<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('svix_id', 120)->unique()->nullable();
            $table->string('external_reference', 100)->nullable()->index();
            $table->string('payment_code', 60)->nullable()->index();
            $table->string('event_type', 60);
            $table->enum('verification_method', ['svix_hmac', 'webhook_token', 'none']);
            $table->boolean('signature_valid')->default(false);
            $table->unsignedSmallInteger('http_status_returned');
            $table->enum('processing_status', [
                'processed',
                'duplicate_ignored',
                'late_settled',
                'rejected_invalid_auth',
                'failed',
            ]);
            $table->text('error_message')->nullable();
            $table->json('headers_json')->nullable();
            $table->json('payload_json');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
    }
};
