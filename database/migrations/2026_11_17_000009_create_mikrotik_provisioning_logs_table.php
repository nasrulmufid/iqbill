<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_provisioning_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('mikrotik_router_id')->constrained('mikrotik_routers');
            $table->enum('action', ['create_secret', 'isolate', 'restore', 'update_profile', 'disconnect_session']);
            $table->enum('trigger_source', ['webhook_paid', 'scheduler_isolir', 'manual_noc']);
            $table->boolean('is_success');
            $table->text('response_message')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_provisioning_logs');
    }
};
