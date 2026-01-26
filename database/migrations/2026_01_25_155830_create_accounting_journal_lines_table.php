<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Parent journal
            $table->uuid('journal_id')->index();

            // Duplicate scopes for easier indexing & regulator exports
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();

            // Deterministic ordering
            $table->integer('line_no');

            // Account
            $table->uuid('account_id')->index();

            // D/C indicator, amount is always positive
            $table->char('dc', 1);
            $table->decimal('amount', 18, 3);

            // Optional description per line
            $table->string('description', 255)->nullable();

            /**
             * FIFO traceability (for exact entries derived from lots)
             * - For GR IN: line can reference lot_id for Inventory DR (optional)
             * - For Kitchen OUT: CR Inventory can reference lot_id slice
             * - For COGS DR: can also reference lot_id slice (recommended)
             */
            $table->uuid('inventory_lot_id')->nullable()->index();
            $table->uuid('inventory_item_id')->nullable()->index();

            // If you want stronger trace: link to inventory_movements row that caused this line
            $table->uuid('inventory_movement_id')->nullable()->index();

            // Quantity and unit_cost capture for audit (optional but very useful)
            $table->decimal('qty', 12, 3)->nullable();
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->string('currency', 10)->default('IDR');
            $table->decimal('fx_rate', 18, 6)->default(1);

            $table->jsonb('meta')->nullable();

            $table->timestamps();

            $table->unique(['journal_id', 'line_no'], 'acct_journal_lines_journal_lineno_uq');
        });

        // FK: journal header
        DB::statement("
            ALTER TABLE accounting_journal_lines
            ADD CONSTRAINT acct_lines_journal_fk
            FOREIGN KEY (journal_id) REFERENCES accounting_journals(id)
            ON UPDATE CASCADE ON DELETE RESTRICT
        ");

        // FK: COA
        DB::statement("
            ALTER TABLE accounting_journal_lines
            ADD CONSTRAINT acct_lines_account_fk
            FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
            ON UPDATE CASCADE ON DELETE RESTRICT
        ");

        // Optional FK: inventory lots/items/movements (use RESTRICT to preserve audit)
        DB::statement("
            ALTER TABLE accounting_journal_lines
            ADD CONSTRAINT acct_lines_lot_fk
            FOREIGN KEY (inventory_lot_id) REFERENCES inventory_lots(id)
            ON UPDATE CASCADE ON DELETE SET NULL
        ");

        DB::statement("
            ALTER TABLE accounting_journal_lines
            ADD CONSTRAINT acct_lines_item_fk
            FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
            ON UPDATE CASCADE ON DELETE SET NULL
        ");

        DB::statement("
            ALTER TABLE accounting_journal_lines
            ADD CONSTRAINT acct_lines_move_fk
            FOREIGN KEY (inventory_movement_id) REFERENCES inventory_movements(id)
            ON UPDATE CASCADE ON DELETE SET NULL
        ");

        // Checks
        DB::statement("
            ALTER TABLE accounting_journal_lines
            ADD CONSTRAINT acct_lines_dc_chk
            CHECK (dc IN ('D','C'))
        ");

        DB::statement("
            ALTER TABLE accounting_journal_lines
            ADD CONSTRAINT acct_lines_amount_pos_chk
            CHECK (amount >= 0)
        ");

        DB::statement("
            ALTER TABLE accounting_journal_lines
            ADD CONSTRAINT acct_lines_qty_nonneg_chk
            CHECK (qty IS NULL OR qty >= 0)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_lines');
    }
};
