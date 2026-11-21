<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150)->nullable();
            $table->string('target_segment', 60)->default('all');
            $table->json('segment_filters')->nullable();
            $table->string('template_key', 60)->nullable();
            $table->text('message_body');
            $table->string('dispatch_mode', 20)->default('sync');
            $table->unsignedSmallInteger('delay_seconds')->default(2);
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 30)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('whatsapp_notification_logs', function (Blueprint $table) {
            $table->foreignId('broadcast_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('whatsapp_broadcasts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_notification_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('broadcast_id');
        });

        Schema::dropIfExists('whatsapp_broadcasts');
    }
};
