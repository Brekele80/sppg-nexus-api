<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // 1) Ensure a DEFAULT company exists (reuse if present)
            $default = DB::table('companies')->where('code', 'DEFAULT')->first();

            if (!$default) {
                $companyId = (string) Str::uuid();
                DB::table('companies')->insert([
                    'id' => $companyId,
                    'name' => 'Default Company',
                    'code' => 'DEFAULT',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $companyId = (string) $default->id;
            }

            // 2) Backfill branches.company_id
            DB::table('branches')
                ->whereNull('company_id')
                ->update([
                    'company_id' => $companyId,
                    'updated_at' => now(),
                ]);

            // 3) Backfill profiles.company_id
            DB::table('profiles')
                ->whereNull('company_id')
                ->update([
                    'company_id' => $companyId,
                    'updated_at' => now(),
                ]);

            // 4) Backfill profile_branch_access.company_id from profile.company_id
            //    (If you already have rows with NULL company_id, repair them)
            if (Schema::hasTable('profile_branch_access')) {
                DB::statement("
                    UPDATE profile_branch_access pba
                    SET company_id = p.company_id,
                        updated_at = NOW()
                    FROM profiles p
                    WHERE pba.profile_id = p.id
                      AND (pba.company_id IS NULL OR pba.company_id <> p.company_id)
                ");
            }
        });

        // 5) Enforce NOT NULL (must happen after backfill)
        DB::statement("ALTER TABLE branches ALTER COLUMN company_id SET NOT NULL");
        DB::statement("ALTER TABLE profiles ALTER COLUMN company_id SET NOT NULL");

        // 6) Fix FK constraints so NOT NULL is consistent:
        //    - If company_id is NOT NULL, ON DELETE SET NULL is invalid semantics.
        //    - Use ON DELETE RESTRICT.
        //
        // NOTE: We drop constraints by name IF EXISTS to avoid deployment breaks.
        DB::statement("ALTER TABLE branches DROP CONSTRAINT IF EXISTS branches_company_id_foreign");
        DB::statement("ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_company_id_foreign");

        DB::statement("
            ALTER TABLE branches
            ADD CONSTRAINT branches_company_id_foreign
            FOREIGN KEY (company_id) REFERENCES companies(id)
            ON DELETE RESTRICT
        ");

        DB::statement("
            ALTER TABLE profiles
            ADD CONSTRAINT profiles_company_id_foreign
            FOREIGN KEY (company_id) REFERENCES companies(id)
            ON DELETE RESTRICT
        ");

        // 7) Add FK for profile_branch_access.company_id (optional but recommended)
        if (Schema::hasTable('profile_branch_access')) {
            DB::statement("ALTER TABLE profile_branch_access DROP CONSTRAINT IF EXISTS profile_branch_access_company_id_foreign");
            DB::statement("
                ALTER TABLE profile_branch_access
                ADD CONSTRAINT profile_branch_access_company_id_foreign
                FOREIGN KEY (company_id) REFERENCES companies(id)
                ON DELETE CASCADE
            ");
        }
    }

    public function down(): void
    {
        // Keep down conservative. We only relax things if you really rollback.
        // WARNING: If you revert to SET NULL, you must also DROP NOT NULL.
        DB::statement("ALTER TABLE branches DROP CONSTRAINT IF EXISTS branches_company_id_foreign");
        DB::statement("ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_company_id_foreign");
        DB::statement("ALTER TABLE profile_branch_access DROP CONSTRAINT IF EXISTS profile_branch_access_company_id_foreign");

        DB::statement("ALTER TABLE branches ALTER COLUMN company_id DROP NOT NULL");
        DB::statement("ALTER TABLE profiles ALTER COLUMN company_id DROP NOT NULL");

        // Restore old style (SET NULL) if you want rollback behavior:
        DB::statement("
            ALTER TABLE branches
            ADD CONSTRAINT branches_company_id_foreign
            FOREIGN KEY (company_id) REFERENCES companies(id)
            ON DELETE SET NULL
        ");
        DB::statement("
            ALTER TABLE profiles
            ADD CONSTRAINT profiles_company_id_foreign
            FOREIGN KEY (company_id) REFERENCES companies(id)
            ON DELETE SET NULL
        ");
    }
};
