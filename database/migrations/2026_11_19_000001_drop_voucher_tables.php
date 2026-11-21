<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('voucher_batches');
        Schema::dropIfExists('voucher_templates');

        DB::table('packages')
            ->where('service_type', 'hotspot')
            ->update(['service_type' => 'pppoe']);
    }

    public function down(): void
    {
        // Fitur Hotspot & Voucher dihapus permanen
    }
};
