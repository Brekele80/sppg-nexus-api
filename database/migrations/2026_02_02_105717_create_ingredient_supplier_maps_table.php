<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ingredient_supplier_maps', function (Blueprint $table) {
            $table->id();

            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();

            $table->unsignedBigInteger('inventory_item_id')->index();
            $table->unsignedBigInteger('supplier_id')->index();

            $table->string('supplier_sku')->nullable();
            $table->string('supplier_unit'); // kg, pcs, liter
            $table->decimal('unit_conversion_factor', 12, 6);
            // ingredient_unit * factor = supplier_unit

            $table->boolean('is_preferred')->default(true);
            $table->timestamps();

            $table->unique([
                'company_id',
                'branch_id',
                'inventory_item_id',
                'supplier_id'
            ], 'ingredient_supplier_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_supplier_maps');
    }
};
