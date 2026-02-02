<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipe_id')->index();

            $table->uuid('inventory_item_id')->index();
            $table->decimal('qty_per_serving', 12, 4);
            $table->string('unit');

            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('recipe_ingredients');
    }
};
