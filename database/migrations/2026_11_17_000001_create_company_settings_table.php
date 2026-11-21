<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 150);
            $table->text('company_address')->nullable();
            $table->string('company_phone', 30)->nullable();
            $table->string('invoice_prefix', 15)->default('INV');
            $table->string('payment_prefix', 15)->default('PAY');
            $table->unsignedTinyInteger('default_due_days')->default(7);
            $table->unsignedTinyInteger('grace_period_days')->default(3);
            $table->boolean('pregenerate_qris_on_invoice')->default(true);
            $table->enum('sumopod_environment', ['sandbox', 'live'])->default('sandbox');
            $table->text('sumopod_api_key_encrypted')->nullable();
            $table->enum('sumopod_webhook_verification_mode', ['signature', 'token', 'auto'])->default('auto');
            $table->text('sumopod_signing_secret_encrypted')->nullable();
            $table->text('sumopod_webhook_token_encrypted')->nullable();
            $table->enum('sumopod_fee_bearer', ['merchant', 'customer'])->default('merchant');
            $table->string('wa_gateway_url', 255)->nullable();
            $table->text('wa_api_token_encrypted')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
