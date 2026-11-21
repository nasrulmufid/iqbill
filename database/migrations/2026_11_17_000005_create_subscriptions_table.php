<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages');
            $table->foreignId('mikrotik_router_id')->constrained('mikrotik_routers');
            $table->string('service_username', 80)->unique();
            $table->text('service_password_encrypted');
            $table->string('static_ip_address', 45)->nullable();
            $table->unsignedTinyInteger('billing_day')->default(1);
            $table->date('next_billing_date')->index();
            $table->enum('status', ['pending_installation', 'active', 'isolated', 'suspended', 'terminated'])
                ->default('pending_installation');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('isolated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
