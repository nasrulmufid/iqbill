<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->enum('service_type', ['pppoe', 'hotspot'])->default('pppoe');
            $table->string('mikrotik_profile_name', 80);
            $table->unsignedSmallInteger('speed_download_mbps');
            $table->unsignedSmallInteger('speed_upload_mbps');
            $table->decimal('price', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
