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
            if (!Schema::hasColumn('company_settings', 'sumopod_sandbox_base_url')) {
                $table->string('sumopod_sandbox_base_url', 255)->nullable()->after('sumopod_environment');
            }
            if (!Schema::hasColumn('company_settings', 'sumopod_live_base_url')) {
                $table->string('sumopod_live_base_url', 255)->nullable()->after('sumopod_sandbox_base_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $dropColumns = [];
            if (Schema::hasColumn('company_settings', 'sumopod_sandbox_base_url')) {
                $dropColumns[] = 'sumopod_sandbox_base_url';
            }
            if (Schema::hasColumn('company_settings', 'sumopod_live_base_url')) {
                $dropColumns[] = 'sumopod_live_base_url';
            }

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
