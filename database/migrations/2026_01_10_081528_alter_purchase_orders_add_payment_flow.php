<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Minimal payment workflow
            $table->string('payment_status', 30)->default('UNPAID')->index(); // UNPAID, PROOF_UPLOADED, SUPPLIER_CONFIRMED, PAID

            // Where proof is stored (local/public disk)
            $table->string('payment_proof_path')->nullable();

            // Audit stamps
            $table->timestampTz('payment_submitted_at')->nullable();
            $table->uuid('payment_submitted_by')->nullable(); // profiles.id (accounting user)

            $table->timestampTz('payment_confirmed_at')->nullable();
            $table->uuid('payment_confirmed_by')->nullable(); // profiles.id (supplier user)

            $table->timestampTz('paid_at')->nullable();
        });

        // Optional FKs if your DB has profiles table (you do)
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreign('payment_submitted_by')->references('id')->on('profiles')->nullOnDelete();
            $table->foreign('payment_confirmed_by')->references('id')->on('profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['payment_submitted_by']);
            $table->dropForeign(['payment_confirmed_by']);

            $table->dropColumn([
                'payment_status',
                'payment_proof_path',
                'payment_submitted_at',
                'payment_submitted_by',
                'payment_confirmed_at',
                'payment_confirmed_by',
                'paid_at',
            ]);
        });
    }
};
