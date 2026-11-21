<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mikrotik_router_id')->constrained('mikrotik_routers')->cascadeOnDelete();
            $table->enum('backup_type', ['export_rsc', 'binary_backup'])->default('export_rsc');
            $table->string('filename', 180);
            $table->string('file_path', 255);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_backups');
    }
};
