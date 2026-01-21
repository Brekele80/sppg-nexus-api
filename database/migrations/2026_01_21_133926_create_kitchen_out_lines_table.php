<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kitchen_out_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->uuid('kitchen_out_id')->index();
            $t->uuid('inventory_item_id')->index();

            $t->decimal('qty', 12, 3);
            $t->text('remarks')->nullable();

            $t->timestampsTz();

            $t->unique(['kitchen_out_id', 'inventory_item_id'], 'kitchen_out_lines_out_item_ux');

            $t->foreign('kitchen_out_id')
                ->references('id')->on('kitchen_outs')
                ->onDelete('cascade');

            $t->foreign('inventory_item_id')
                ->references('id')->on('inventory_items');
        });

        // Postgres check constraint: qty > 0
        DB::statement("alter table kitchen_out_lines add constraint kitchen_out_lines_qty_gt_zero_chk check (qty > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_out_lines');
    }
};
