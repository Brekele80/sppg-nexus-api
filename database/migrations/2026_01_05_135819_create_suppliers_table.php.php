<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->string('code', 50)->unique(); // e.g. SUP-001
            $t->string('name');
            $t->string('email')->nullable()->index();
            $t->string('phone', 50)->nullable();

            // Optional: allow scoping suppliers to a branch. If suppliers are global, keep nullable.
            $t->uuid('branch_id')->nullable()->index();

            $t->boolean('is_active')->default(true)->index();
            $t->jsonb('meta')->nullable();

            $t->timestampsTz();

            $t->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
