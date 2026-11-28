<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('auto_backup_enabled')->default(false)->after('google_maps_api_key_encrypted');
            $table->string('auto_backup_scope', 20)->default('full')->after('auto_backup_enabled');
            $table->string('auto_backup_schedule', 20)->default('daily')->after('auto_backup_scope');
            $table->string('auto_backup_time', 5)->default('02:00')->after('auto_backup_schedule');
            $table->unsignedTinyInteger('auto_backup_day_of_week')->default(0)->after('auto_backup_time');
            $table->unsignedTinyInteger('auto_backup_day_of_month')->default(1)->after('auto_backup_day_of_week');
            $table->unsignedSmallInteger('auto_backup_retention_count')->default(7)->after('auto_backup_day_of_month');
            $table->text('auto_backup_passphrase_encrypted')->nullable()->after('auto_backup_retention_count');
            $table->boolean('auto_backup_send_telegram')->default(true)->after('auto_backup_passphrase_encrypted');
            $table->string('auto_backup_telegram_chat_id', 60)->nullable()->after('auto_backup_send_telegram');
            $table->timestamp('auto_backup_last_run_at')->nullable()->after('auto_backup_telegram_chat_id');
            $table->string('auto_backup_last_status', 40)->nullable()->after('auto_backup_last_run_at');
            $table->string('auto_backup_last_error', 500)->nullable()->after('auto_backup_last_status');
        });

        Schema::table('system_backups', function (Blueprint $table) {
            $table->string('trigger_source', 20)->default('manual')->after('scope')->index();
            $table->timestamp('telegram_sent_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('system_backups', function (Blueprint $table) {
            $table->dropColumn(['trigger_source', 'telegram_sent_at']);
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'auto_backup_enabled',
                'auto_backup_scope',
                'auto_backup_schedule',
                'auto_backup_time',
                'auto_backup_day_of_week',
                'auto_backup_day_of_month',
                'auto_backup_retention_count',
                'auto_backup_passphrase_encrypted',
                'auto_backup_send_telegram',
                'auto_backup_telegram_chat_id',
                'auto_backup_last_run_at',
                'auto_backup_last_status',
                'auto_backup_last_error',
            ]);
        });
    }
};
