<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('menu_recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('menu_id')->index();

            $table->string('name');
            $table->integer('servings'); // how many people this recipe feeds

            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('menu_recipes');
    }
};
