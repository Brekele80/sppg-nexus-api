<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Multi-tenant boundary
            $table->uuid('company_id')->index();

            // Optional hierarchy (e.g., Inventory -> Inventory - Raw Materials)
            $table->uuid('parent_id')->nullable()->index();

            // COA identifiers
            $table->string('code', 32);
            $table->string('name', 180);

            // ACCOUNT TYPE: ASSET/LIABILITY/EQUITY/REVENUE/EXPENSE
            $table->string('type', 20);

            // Normal balance: D or C
            $table->char('normal_balance', 1);

            $table->boolean('is_active')->default(true);

            // For regulator mapping, reporting tags, etc.
            $table->jsonb('meta')->nullable();

            $table->timestamps();

            // Uniqueness per company
            $table->unique(['company_id', 'code'], 'coa_company_code_uq');
        });

        // Postgres check constraints (safe, explicit)
        DB::statement("
            ALTER TABLE chart_of_accounts
            ADD CONSTRAINT coa_type_chk
            CHECK (type IN ('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'))
        ");

        DB::statement("
            ALTER TABLE chart_of_accounts
            ADD CONSTRAINT coa_normal_balance_chk
            CHECK (normal_balance IN ('D','C'))
        ");

        // Optional: parent_id references itself (no cascade delete)
        DB::statement("
            ALTER TABLE chart_of_accounts
            ADD CONSTRAINT coa_parent_fk
            FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(id)
            ON UPDATE CASCADE ON DELETE SET NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
