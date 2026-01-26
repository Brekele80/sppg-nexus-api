<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_journals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Multi-tenant boundary + branch scope
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();

            // Journal date (accounting date)
            $table->date('journal_date')->index();

            /**
             * Source linkage (document that generated this journal)
             * Examples:
             *  - source_type='GOODS_RECEIPT', source_id=<gr_id>
             *  - source_type='KITCHEN_OUT', source_id=<ko_id>
             *  - source_type='STOCK_ADJUSTMENT', source_id=<sa_id>
             */
            $table->string('source_type', 50)->index();
            $table->uuid('source_id')->index();

            // Ref linkage (optional, for UI navigation)
            $table->string('ref_type', 80)->nullable()->index();
            $table->uuid('ref_id')->nullable()->index();

            /**
             * Status is append-only:
             * - POSTED: immutable journal
             * - VOIDED: journal remains, plus reversal journal links to it
             */
            $table->string('status', 20)->default('POSTED')->index();

            // Currency & FX (keep for future cross-currency; IDR default)
            $table->string('currency', 10)->default('IDR');
            $table->decimal('fx_rate', 18, 6)->default(1);

            // Freeform
            $table->string('memo', 255)->nullable();

            // Posting audit
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();

            // Void audit (voided journal is not deleted)
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();

            // Reversal links
            $table->uuid('reversal_of_journal_id')->nullable()->index();

            // Deterministic hash of lines (optional but recommended)
            $table->string('checksum', 128)->nullable();

            // Idempotency safety at accounting layer
            $table->string('idempotency_key', 120)->nullable();

            $table->jsonb('meta')->nullable();

            $table->timestamps();

            // Prevent duplicates for same source
            $table->unique(['company_id', 'source_type', 'source_id'], 'acct_journal_company_source_uq');

            // Optional: idempotency uniqueness (only if you want strict enforcement)
            $table->unique(['company_id', 'idempotency_key'], 'acct_journal_company_idemp_uq');
        });

        // Foreign key to branches (defense-in-depth, optional if branches table exists)
        DB::statement("
            ALTER TABLE accounting_journals
            ADD CONSTRAINT acct_journals_branch_fk
            FOREIGN KEY (branch_id) REFERENCES branches(id)
            ON UPDATE CASCADE ON DELETE RESTRICT
        ");

        // Self FK for reversal chain
        DB::statement("
            ALTER TABLE accounting_journals
            ADD CONSTRAINT acct_journals_reversal_fk
            FOREIGN KEY (reversal_of_journal_id) REFERENCES accounting_journals(id)
            ON UPDATE CASCADE ON DELETE SET NULL
        ");

        // Check constraints
        DB::statement("
            ALTER TABLE accounting_journals
            ADD CONSTRAINT acct_journals_status_chk
            CHECK (status IN ('POSTED','VOIDED'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journals');
    }
};
