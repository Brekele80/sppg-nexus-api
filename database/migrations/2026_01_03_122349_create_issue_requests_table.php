<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->uuid('branch_id')->index();
            $t->string('ir_number')->unique(); // e.g. IR-20260103-XXXX
            $t->string('status', 30)->default('DRAFT')->index(); // DRAFT,SUBMITTED,APPROVED,ISSUED,REJECTED

            $t->uuid('created_by')->index();        // CHEF
            $t->uuid('submitted_by')->nullable()->index();
            $t->uuid('approved_by')->nullable()->index(); // DC_ADMIN
            $t->uuid('issued_by')->nullable()->index();   // DC_ADMIN

            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('issued_at')->nullable();

            $t->text('notes')->nullable();
            $t->jsonb('meta')->nullable();

            $t->timestampsTz();
        });

        Schema::create('issue_request_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('issue_request_id')->index();

            $t->string('item_name');
            $t->string('unit', 30)->nullable();

            $t->decimal('requested_qty', 12, 3);
            $t->decimal('approved_qty', 12, 3)->default(0);
            $t->decimal('issued_qty', 12, 3)->default(0);

            $t->text('remarks')->nullable();

            $t->timestampsTz();

            $t->foreign('issue_request_id')
              ->references('id')->on('issue_requests')
              ->onDelete('cascade');
        });

        Schema::create('issue_request_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('issue_request_id')->index();
            $t->uuid('actor_id')->nullable()->index();

            $t->string('event', 50)->index(); // CREATED, SUBMITTED, APPROVED, ISSUED, REJECTED
            $t->text('message')->nullable();
            $t->jsonb('meta')->nullable();

            $t->timestampsTz();

            $t->foreign('issue_request_id')
              ->references('id')->on('issue_requests')
              ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_request_events');
        Schema::dropIfExists('issue_request_items');
        Schema::dropIfExists('issue_requests');
    }
};
