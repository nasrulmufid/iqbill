<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('mikrotik_sync_status', 30)->default('synced')->after('isolated_at')->index();
            $table->string('mikrotik_pending_action', 30)->nullable()->after('mikrotik_sync_status');
            $table->boolean('mikrotik_pending_notify')->default(false)->after('mikrotik_pending_action');
            $table->foreignId('mikrotik_pending_invoice_id')
                ->nullable()
                ->after('mikrotik_pending_notify')
                ->constrained('invoices')
                ->nullOnDelete();
            $table->unsignedInteger('mikrotik_sync_attempts')->default(0)->after('mikrotik_pending_invoice_id');
            $table->text('mikrotik_last_sync_error')->nullable()->after('mikrotik_sync_attempts');
            $table->timestamp('mikrotik_last_synced_at')->nullable()->after('mikrotik_last_sync_error');
        });

        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->boolean('is_reachable')->default(true)->after('is_active');
            $table->text('last_health_error')->nullable()->after('last_health_check_at');
        });

        Schema::table('mikrotik_provisioning_logs', function (Blueprint $table) {
            $table->string('trigger_source', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mikrotik_pending_invoice_id');
            $table->dropColumn([
                'mikrotik_sync_status',
                'mikrotik_pending_action',
                'mikrotik_pending_notify',
                'mikrotik_sync_attempts',
                'mikrotik_last_sync_error',
                'mikrotik_last_synced_at',
            ]);
        });

        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropColumn(['is_reachable', 'last_health_error']);
        });
    }
};
