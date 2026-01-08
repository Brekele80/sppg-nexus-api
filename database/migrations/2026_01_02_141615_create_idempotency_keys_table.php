<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 120);
            $t->uuid('user_id');
            $t->string('method', 10);
            $t->string('path', 255);
            $t->string('request_hash', 64);
            $t->integer('response_status')->nullable();
            $t->jsonb('response_body')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('locked_at')->nullable();

            $t->unique(['key', 'user_id', 'method', 'path']);
            $t->index(['user_id', 'created_at']);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
