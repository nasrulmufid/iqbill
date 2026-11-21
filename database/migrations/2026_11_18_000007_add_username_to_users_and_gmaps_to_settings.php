<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 80)->nullable()->unique()->after('email');
            $table->string('email', 150)->nullable()->change();
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->text('google_maps_api_key_encrypted')->nullable()->after('telegram_notify_error');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('google_maps_api_key_encrypted');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
            $table->string('email', 150)->nullable(false)->change();
        });
    }
};
