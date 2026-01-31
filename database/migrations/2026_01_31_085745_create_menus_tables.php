<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up() {
    Schema::create('menus', function (Blueprint $t) {
      $t->uuid('id')->primary();
      $t->uuid('company_id')->index();
      $t->string('name');
      $t->integer('version');
      $t->string('status')->default('DRAFT');
      $t->uuid('created_by');
      $t->timestamp('created_at')->useCurrent();

      $t->unique(['company_id', 'name', 'version']);
    });

    Schema::create('menu_items', function (Blueprint $t) {
      $t->uuid('id')->primary();
      $t->uuid('menu_id')->index();
      $t->string('name');
      $t->string('category')->nullable();
      $t->integer('servings');
      $t->timestamp('created_at')->useCurrent();
    });

    Schema::create('menu_assignments', function (Blueprint $t) {
      $t->uuid('id')->primary();
      $t->uuid('company_id')->index();
      $t->uuid('branch_id')->index();
      $t->uuid('menu_id')->index();
      $t->date('start_date');
      $t->date('end_date');
      $t->timestamp('created_at')->useCurrent();

      $t->unique(['branch_id', 'start_date', 'end_date']);
    });
  }

  public function down() {
    Schema::dropIfExists('menu_assignments');
    Schema::dropIfExists('menu_items');
    Schema::dropIfExists('menus');
  }
};
