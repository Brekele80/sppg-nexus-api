<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->uuid('branch_id')->index();
            $t->uuid('purchase_order_id')->index();

            $t->string('gr_number')->unique(); // e.g. GR-20260102-XXXX
            $t->string('status', 30)->default('DRAFT')->index(); // DRAFT, SUBMITTED, RECEIVED, DISCREPANCY, CLOSED (optional)

            $t->uuid('created_by')->index();      // DC_ADMIN
            $t->uuid('submitted_by')->nullable()->index();
            $t->uuid('received_by')->nullable()->index(); // DC_ADMIN (or KA_SPPG if you want)

            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('received_at')->nullable();

            $t->text('notes')->nullable();
            $t->jsonb('meta')->nullable(); // qc flags, attachments urls later

            $t->timestampsTz();

            // Recommended: 1 GR per PO (simple + safe). If you want partial deliveries, remove this
            // and enforce in the service layer instead.
            $t->unique(['purchase_order_id']);

            // Foreign keys (assumes these tables exist in your project)
            $t->foreign('branch_id')->references('id')->on('branches');
            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders');

            // Profiles table (your app uses profiles as users). If you prefer no hard FK to auth table, remove these.
            $t->foreign('created_by')->references('id')->on('profiles');
            $t->foreign('submitted_by')->references('id')->on('profiles');
            $t->foreign('received_by')->references('id')->on('profiles');
        });

        Schema::create('goods_receipt_items', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->uuid('goods_receipt_id')->index();
            $t->uuid('purchase_order_item_id')->index();

            $t->string('item_name');
            $t->string('unit', 30)->nullable();

            $t->decimal('ordered_qty', 12, 3);
            $t->decimal('received_qty', 12, 3)->default(0);
            $t->decimal('rejected_qty', 12, 3)->default(0);

            $t->string('discrepancy_reason')->nullable();
            $t->text('remarks')->nullable();

            $t->timestampsTz();

            // Prevent duplicate rows for the same PO item inside one GR
            $t->unique(['goods_receipt_id', 'purchase_order_item_id']);

            $t->foreign('goods_receipt_id')
                ->references('id')
                ->on('goods_receipts')
                ->onDelete('cascade');

            // Assumes your PO items table is named purchase_order_items
            $t->foreign('purchase_order_item_id')
                ->references('id')
                ->on('purchase_order_items');
        });

        Schema::create('goods_receipt_events', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->uuid('goods_receipt_id')->index();
            $t->uuid('actor_id')->nullable()->index();

            $t->string('event', 50)->index(); // CREATED, SUBMITTED, RECEIVED, DISCREPANCY
            $t->text('message')->nullable();
            $t->jsonb('meta')->nullable();

            $t->timestampsTz();

            $t->foreign('goods_receipt_id')
                ->references('id')
                ->on('goods_receipts')
                ->onDelete('cascade');

            // Optional: enforce actor is a profile id when present
            $t->foreign('actor_id')->references('id')->on('profiles');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_events');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
