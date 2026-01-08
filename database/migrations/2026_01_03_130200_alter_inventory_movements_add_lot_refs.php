<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            if (!Schema::hasColumn('inventory_movements', 'inventory_lot_id')) {
                $t->uuid('inventory_lot_id')->nullable()->index();
            }
            if (!Schema::hasColumn('inventory_movements', 'source_type')) {
                $t->string('source_type', 40)->nullable()->index(); // GR, ISSUE, ADJUSTMENT
            }
            if (!Schema::hasColumn('inventory_movements', 'source_id')) {
                $t->uuid('source_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            if (Schema::hasColumn('inventory_movements', 'inventory_lot_id')) $t->dropColumn('inventory_lot_id');
            if (Schema::hasColumn('inventory_movements', 'source_type')) $t->dropColumn('source_type');
            if (Schema::hasColumn('inventory_movements', 'source_id')) $t->dropColumn('source_id');
        });
    }
};
