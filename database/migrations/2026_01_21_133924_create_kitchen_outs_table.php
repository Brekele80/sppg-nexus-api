<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kitchen_outs', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->uuid('branch_id')->index();
            $t->string('out_number'); // unique per branch
            $t->timestampTz('out_at')->useCurrent();

            $t->uuid('created_by')->index();
            $t->jsonb('meta')->default('{}');

            $t->timestampsTz();

            $t->unique(['branch_id', 'out_number'], 'kitchen_outs_branch_outnumber_ux');

            $t->foreign('branch_id')->references('id')->on('branches');
            // If you want FK to profiles, add it; otherwise keep no FK.
            // $t->foreign('created_by')->references('id')->on('profiles');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_outs');
    }
};
