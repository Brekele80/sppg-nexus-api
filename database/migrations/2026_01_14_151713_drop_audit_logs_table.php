<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy table; procurement/commercial trace is audit_ledger only.
        Schema::dropIfExists('audit_logs');
    }

    public function down(): void
    {
        // Intentionally no-op: we do not resurrect legacy audit tables in production.
        // If you truly need it back, restore from an earlier migration snapshot.
    }
};
