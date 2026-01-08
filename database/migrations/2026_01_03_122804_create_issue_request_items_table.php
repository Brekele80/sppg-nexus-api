<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Allocation table: how inventory was consumed for an issue request (no lots yet)
        Schema::create('issue_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->uuid('issue_request_item_id')->index();

            // Allocate from an inventory_item directly (works with your current schema)
            $t->uuid('inventory_item_id')->index();

            $t->decimal('qty', 12, 3);
            $t->decimal('unit_cost', 14, 2)->default(0); // keep for future avg-cost
            $t->timestampsTz();

            $t->foreign('issue_request_item_id')
                ->references('id')->on('issue_request_items')
                ->onDelete('cascade');

            $t->foreign('inventory_item_id')
                ->references('id')->on('inventory_items')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_allocations');
    }
};
