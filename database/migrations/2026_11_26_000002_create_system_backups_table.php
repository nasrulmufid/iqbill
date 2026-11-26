<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_backups', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 30)->index();
            $table->string('filename', 190)->unique();
            $table->string('file_path', 255);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->string('checksum_sha256', 64);
            $table->string('encryption_cipher', 40)->default('aes-256-gcm');
            $table->unsignedInteger('tables_count')->default(0);
            $table->unsignedBigInteger('records_count')->default(0);
            $table->string('app_version', 40)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_backups');
    }
};
