<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $t) {
            $t->boolean('inventory_posted')->default(false)->index();
            $t->timestampTz('inventory_posted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $t) {
            $t->dropColumn(['inventory_posted', 'inventory_posted_at']);
        });
    }
};
