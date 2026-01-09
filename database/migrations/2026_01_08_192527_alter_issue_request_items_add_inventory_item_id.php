<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('issue_request_items', function (Blueprint $table) {
            // needed because controller writes it
            if (!Schema::hasColumn('issue_request_items', 'inventory_item_id')) {
                $table->uuid('inventory_item_id')->nullable()->after('issue_request_id');
            }

            // your issue() method updates issued_qty; ensure it exists
            if (!Schema::hasColumn('issue_request_items', 'issued_qty')) {
                $table->decimal('issued_qty', 18, 3)->default(0)->after('requested_qty');
            }

            // your issue() reads approved_qty; ensure it exists (optional but recommended)
            if (!Schema::hasColumn('issue_request_items', 'approved_qty')) {
                $table->decimal('approved_qty', 18, 3)->nullable()->after('requested_qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('issue_request_items', function (Blueprint $table) {
            if (Schema::hasColumn('issue_request_items', 'inventory_item_id')) $table->dropColumn('inventory_item_id');
            if (Schema::hasColumn('issue_request_items', 'issued_qty')) $table->dropColumn('issued_qty');
            if (Schema::hasColumn('issue_request_items', 'approved_qty')) $table->dropColumn('approved_qty');
        });
    }
};
