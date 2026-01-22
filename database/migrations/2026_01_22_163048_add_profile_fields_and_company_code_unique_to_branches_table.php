<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Columns (idempotent)
        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'city')) {
                $table->string('city', 255)->nullable()->after('code');
            }
            if (!Schema::hasColumn('branches', 'province')) {
                $table->string('province', 255)->nullable()->after('city');
            }
            if (!Schema::hasColumn('branches', 'address')) {
                $table->text('address')->nullable()->after('province');
            }
            if (!Schema::hasColumn('branches', 'phone')) {
                $table->string('phone', 50)->nullable()->after('address');
            }
        });

        /**
         * 2) Constraints / indexes
         * Use raw SQL with IF EXISTS to be Postgres-safe and environment-safe.
         * Your DB already has branches_company_code_unique, so we do not assume anything.
         */
        DB::statement('alter table public.branches drop constraint if exists branches_code_unique');
        DB::statement('alter table public.branches drop constraint if exists branches_company_code_unique');

        DB::statement('alter table public.branches add constraint branches_company_code_unique unique (company_id, code)');

        DB::statement('create index if not exists branches_company_name_index on public.branches (company_id, name)');
        DB::statement('create index if not exists branches_company_city_index on public.branches (company_id, city)');
    }

    public function down(): void
    {
        // Reverse constraints first
        DB::statement('alter table public.branches drop constraint if exists branches_company_code_unique');

        // Optional: restore old (NOT tenant-safe). Only do this if you truly need rollback symmetry.
        // DB::statement('alter table public.branches add constraint branches_code_unique unique (code)');

        DB::statement('drop index if exists branches_company_name_index');
        DB::statement('drop index if exists branches_company_city_index');

        // Drop columns (idempotent)
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'phone')) $table->dropColumn('phone');
            if (Schema::hasColumn('branches', 'address')) $table->dropColumn('address');
            if (Schema::hasColumn('branches', 'province')) $table->dropColumn('province');
            if (Schema::hasColumn('branches', 'city')) $table->dropColumn('city');
        });
    }
};
