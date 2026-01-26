<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Optional defensive check: use HAVING on expression (Postgres-safe)
        $dupes = DB::table('purchase_orders')
            ->select('rab_version_id', DB::raw('count(*) as cnt'))
            ->groupBy('rab_version_id')
            ->havingRaw('count(*) > 1')
            ->limit(1)
            ->get();

        if ($dupes->isNotEmpty()) {
            throw new RuntimeException('Duplicate purchase_orders per rab_version_id exist; cleanup required before adding UNIQUE constraint.');
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unique('rab_version_id', 'purchase_orders_rab_version_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique('purchase_orders_rab_version_id_unique');
        });
    }
};
