<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_adjustments', 'rejected_at')) {
                $table->timestampTz('rejected_at')->nullable()->index();
            }
            if (!Schema::hasColumn('stock_adjustments', 'rejected_by')) {
                $table->uuid('rejected_by')->nullable()->index();
            }
            if (!Schema::hasColumn('stock_adjustments', 'reject_reason')) {
                $table->text('reject_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            if (Schema::hasColumn('stock_adjustments', 'rejected_at')) $table->dropColumn('rejected_at');
            if (Schema::hasColumn('stock_adjustments', 'rejected_by')) $table->dropColumn('rejected_by');
            if (Schema::hasColumn('stock_adjustments', 'reject_reason')) $table->dropColumn('reject_reason');
        });
    }
};
