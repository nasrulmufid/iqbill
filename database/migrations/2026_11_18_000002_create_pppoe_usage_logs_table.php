<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pppoe_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('mikrotik_router_id')->constrained('mikrotik_routers')->cascadeOnDelete();
            $table->string('session_id', 80)->nullable();
            $table->string('address', 45)->nullable();
            $table->unsignedBigInteger('rx_bytes_delta')->default(0);
            $table->unsignedBigInteger('tx_bytes_delta')->default(0);
            $table->unsignedBigInteger('last_counter_rx')->default(0);
            $table->unsignedBigInteger('last_counter_tx')->default(0);
            $table->unsignedInteger('uptime_seconds')->default(0);
            $table->date('recorded_date')->index();
            $table->timestamp('recorded_at');

            $table->index(['subscription_id', 'recorded_date'], 'idx_usage_sub_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_usage_logs');
    }
};
