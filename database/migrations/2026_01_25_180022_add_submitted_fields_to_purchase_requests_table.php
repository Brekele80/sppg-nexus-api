<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            // Workflow/audit fields
            $table->timestampTz('submitted_at')->nullable()->after('notes');
            $table->uuid('submitted_by')->nullable()->after('submitted_at');

            // Helpful index for queues/filters
            $table->index(['branch_id', 'status'], 'purchase_requests_branch_status_idx');
            $table->index(['submitted_by'], 'purchase_requests_submitted_by_idx');

            // FK to profiles (matches requested_by FK style)
            $table->foreign('submitted_by', 'purchase_requests_submitted_by_fk')
                ->references('id')
                ->on('profiles')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            // Drop FK + indexes + columns (reverse order)
            $table->dropForeign('purchase_requests_submitted_by_fk');

            $table->dropIndex('purchase_requests_submitted_by_idx');
            $table->dropIndex('purchase_requests_branch_status_idx');

            $table->dropColumn(['submitted_by', 'submitted_at']);
        });
    }
};
