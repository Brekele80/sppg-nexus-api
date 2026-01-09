<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('branch_id')->index();

            $t->string('item_name');
            $t->string('unit', 30)->nullable();

            $t->decimal('on_hand', 12, 3)->default(0);

            $t->timestampsTz();

            $t->unique(['branch_id', 'item_name', 'unit']);
        });

        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('branch_id')->index();
            $t->uuid('inventory_item_id')->index();

            $t->string('type', 30)->index(); // GR_IN, ADJUSTMENT, ISSUE_OUT (future)
            $t->decimal('qty', 12, 3);        // positive for in, negative for out

            $t->uuid('ref_id')->nullable()->index(); // e.g. goods_receipt_id
            $t->string('ref_type', 50)->nullable();  // 'goods_receipts'
            $t->uuid('actor_id')->nullable()->index();

            $t->text('note')->nullable();
            $t->timestampsTz();

            $t->foreign('inventory_item_id')->references('id')->on('inventory_items')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_items');
    }
};
