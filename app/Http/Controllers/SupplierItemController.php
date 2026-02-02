<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up() {
    Schema::create('auto_pr_audits', function (Blueprint $t) {
      $t->uuid('id')->primary();
      $t->uuid('company_id')->index();
      $t->uuid('menu_id')->index();
      $t->uuid('branch_id')->index();
      $t->uuid('pr_id')->nullable()->index();
      $t->uuid('created_by')->index();
      $t->json('summary');
      $t->timestamp('created_at')->useCurrent();
    });
  }

  public function down() {
    Schema::dropIfExists('auto_pr_audits');
  }
};
