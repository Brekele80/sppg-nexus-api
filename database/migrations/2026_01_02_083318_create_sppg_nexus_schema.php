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
        Schema::create('branches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('code')->unique();
            $t->timestamps();
        });

        Schema::create('profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('email')->index();
            $t->string('full_name')->nullable();
            $t->uuid('branch_id')->nullable()->index();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $t) {
            $t->uuid('user_id');
            $t->uuid('role_id');
            $t->timestamps();
            $t->primary(['user_id','role_id']);
            $t->foreign('user_id')->references('id')->on('profiles')->cascadeOnDelete();
            $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('purchase_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('branch_id')->index();
            $t->uuid('requested_by')->index();
            $t->string('status')->default('DRAFT');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('requested_by')->references('id')->on('profiles')->restrictOnDelete();
        });

        Schema::create('purchase_request_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('purchase_request_id')->index();
            $t->string('item_name');
            $t->string('unit');
            $t->decimal('qty',12,3);
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->foreign('purchase_request_id')->references('id')->on('purchase_requests')->cascadeOnDelete();
        });

        Schema::create('rab_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('purchase_request_id')->index();
            $t->integer('version_no');
            $t->uuid('created_by')->index();
            $t->string('status')->default('DRAFT');
            $t->string('currency')->default('IDR');
            $t->decimal('subtotal',14,2)->default(0);
            $t->decimal('tax',14,2)->default(0);
            $t->decimal('total',14,2)->default(0);
            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestamps();
            $t->unique(['purchase_request_id','version_no']);
            $t->foreign('purchase_request_id')->references('id')->on('purchase_requests')->cascadeOnDelete();
            $t->foreign('created_by')->references('id')->on('profiles')->restrictOnDelete();
        });

        Schema::create('rab_line_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('rab_version_id')->index();
            $t->string('item_name');
            $t->string('unit');
            $t->decimal('qty',12,3);
            $t->decimal('unit_price',14,2);
            $t->decimal('line_total',14,2);
            $t->timestamps();
            $t->foreign('rab_version_id')->references('id')->on('rab_versions')->cascadeOnDelete();
        });

        Schema::create('approval_policies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('mode')->default('ANY_OF');
            $t->integer('min_approvals')->default(1);
            $t->string('on_reject')->default('SOFT_REJECT');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('approval_policy_roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('policy_id')->index();
            $t->string('role_code');
            $t->timestamps();
            $t->unique(['policy_id','role_code']);
            $t->foreign('policy_id')->references('id')->on('approval_policies')->cascadeOnDelete();
        });

        Schema::create('approval_decisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('entity_type');
            $t->uuid('entity_id')->index();
            $t->uuid('policy_id')->index();
            $t->uuid('decided_by')->index();
            $t->string('decided_by_role');
            $t->string('decision');
            $t->text('reason')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['entity_type','entity_id','decided_by']);
            $t->foreign('policy_id')->references('id')->on('approval_policies')->restrictOnDelete();
            $t->foreign('decided_by')->references('id')->on('profiles')->restrictOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('actor_id')->index();
            $t->string('action');
            $t->string('entity_type');
            $t->uuid('entity_id')->index();
            $t->jsonb('metadata')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('actor_id')->references('id')->on('profiles')->restrictOnDelete();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_policy_roles');
        Schema::dropIfExists('approval_policies');
        Schema::dropIfExists('rab_line_items');
        Schema::dropIfExists('rab_versions');
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('branches');
    }
};
