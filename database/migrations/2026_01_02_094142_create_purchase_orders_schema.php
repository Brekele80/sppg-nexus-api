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
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('branch_id')->index();
            $t->uuid('purchase_request_id')->index();
            $t->uuid('rab_version_id')->index(); // the approved version
            $t->uuid('created_by')->index();     // PURCHASE_CABANG profile id
            $t->uuid('supplier_id')->nullable()->index(); // SUPABASE profile id with SUPPLIER role

            $t->string('po_number')->unique();
            $t->string('status')->default('DRAFT'); // DRAFT, SENT, CONFIRMED, REJECTED, DELIVERED, CANCELLED
            $t->string('currency')->default('IDR');

            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);

            $t->timestampTz('sent_at')->nullable();
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestampTz('delivered_at')->nullable();

            $t->text('notes')->nullable();
            $t->timestamps();

            $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('purchase_request_id')->references('id')->on('purchase_requests')->restrictOnDelete();
            $t->foreign('rab_version_id')->references('id')->on('rab_versions')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('profiles')->restrictOnDelete();
            $t->foreign('supplier_id')->references('id')->on('profiles')->nullOnDelete();
        });

        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('purchase_order_id')->index();

            $t->string('item_name');
            $t->string('unit');
            $t->decimal('qty', 12, 3);
            $t->decimal('unit_price', 14, 2);
            $t->decimal('line_total', 14, 2);

            $t->timestamps();

            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
        });

        // Optional but recommended: event log for supplier actions / delivery
        Schema::create('purchase_order_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('purchase_order_id')->index();
            $t->uuid('actor_id')->index();
            $t->string('event'); // SENT, CONFIRMED, REJECTED, DELIVERED, NOTE
            $t->text('message')->nullable();
            $t->jsonb('metadata')->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $t->foreign('actor_id')->references('id')->on('profiles')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_events');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
