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
            $table->boolean('usage_tracking_enabled')->default(true)->after('pregenerate_qris_on_invoice');
            $table->string('wa_basic_auth_user', 100)->nullable()->after('wa_api_token_encrypted');
            $table->text('wa_basic_auth_password_encrypted')->nullable()->after('wa_basic_auth_user');
            $table->string('wa_device_id', 100)->nullable()->after('wa_basic_auth_password_encrypted');
            $table->boolean('telegram_enabled')->default(false)->after('wa_device_id');
            $table->text('telegram_bot_token_encrypted')->nullable()->after('telegram_enabled');
            $table->string('telegram_chat_id', 60)->nullable()->after('telegram_bot_token_encrypted');
            $table->boolean('telegram_notify_payment')->default(true)->after('telegram_chat_id');
            $table->boolean('telegram_notify_error')->default(true)->after('telegram_notify_payment');
        });

        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->enum('ros_version_type', ['v6', 'v7'])->default('v7')->after('name');
            $table->unsignedSmallInteger('rest_port')->default(443)->after('api_port');
            $table->unsignedSmallInteger('ssh_port')->default(22)->after('rest_port');
            $table->string('ros_version', 30)->nullable()->after('last_health_check_at');
            $table->timestamp('default_firewall_installed_at')->nullable()->after('ros_version');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('isolir_profile_name', 80)->nullable()->after('static_ip_address');
            $table->unsignedTinyInteger('isolir_day')->nullable()->after('billing_day');
            $table->boolean('auto_isolate')->default(true)->after('isolir_day');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['isolir_profile_name', 'isolir_day', 'auto_isolate']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropColumn(['ros_version_type', 'rest_port', 'ssh_port', 'ros_version', 'default_firewall_installed_at']);
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'usage_tracking_enabled',
                'wa_basic_auth_user',
                'wa_basic_auth_password_encrypted',
                'wa_device_id',
                'telegram_enabled',
                'telegram_bot_token_encrypted',
                'telegram_chat_id',
                'telegram_notify_payment',
                'telegram_notify_error',
            ]);
        });
    }
};
