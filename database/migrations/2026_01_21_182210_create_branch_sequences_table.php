<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('key', 50); // e.g. 'KITCHEN_OUT', 'TRANSFER', etc.
            $table->bigInteger('last_no')->default(0); // last allocated number
            $table->timestampsTz();

            $table->unique(['branch_id', 'key'], 'branch_sequences_branch_key_ux');

            $table->foreign('branch_id', 'branch_sequences_branch_fk')
                ->references('id')->on('branches')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_sequences');
    }
};
