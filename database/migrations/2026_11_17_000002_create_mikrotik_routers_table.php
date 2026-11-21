<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_routers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('host', 120);
            $table->unsignedSmallInteger('api_port')->default(8728);
            $table->boolean('use_ssl')->default(false);
            $table->string('username', 80);
            $table->text('password_encrypted');
            $table->string('isolir_address_list', 80)->default('ISOLIR');
            $table->string('isolir_profile_name', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_routers');
    }
};
