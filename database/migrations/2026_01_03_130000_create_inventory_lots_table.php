<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->uuid('branch_id')->index();
            $t->uuid('inventory_item_id')->index();

            // Origin traceability (optional but strongly recommended)
            $t->uuid('goods_receipt_id')->nullable()->index();
            $t->uuid('goods_receipt_item_id')->nullable()->index();

            $t->string('lot_code', 60)->index(); // e.g LOT-20260102-XXXX
            $t->date('expiry_date')->nullable()->index();

            $t->decimal('received_qty', 12, 3);
            $t->decimal('remaining_qty', 12, 3);

            $t->decimal('unit_cost', 14, 2)->default(0); // IDR
            $t->string('currency', 10)->default('IDR');

            $t->timestampTz('received_at')->nullable()->index();
            $t->timestampsTz();

            $t->unique(['branch_id', 'lot_code']);
            $t->foreign('inventory_item_id')->references('id')->on('inventory_items')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
