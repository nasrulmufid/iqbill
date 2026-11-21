<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('label', 120);
            $table->text('body_template');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('whatsapp_notification_logs', function (Blueprint $table) {
            $table->string('template_type', 60)->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
