<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // 1) Create one default company for all existing records
            $companyId = (string) Str::uuid();

            DB::table('companies')->insert([
                'id' => $companyId,
                'name' => 'Default Company',
                'code' => 'DEFAULT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2) Set company_id for all branches/profiles
            DB::table('branches')->whereNull('company_id')->update([
                'company_id' => $companyId,
                'updated_at' => now(),
            ]);

            DB::table('profiles')->whereNull('company_id')->update([
                'company_id' => $companyId,
                'updated_at' => now(),
            ]);

            // 3) Create access: each profile gets access to its branch_id
            $profiles = DB::table('profiles')
                ->select('id', 'company_id', 'branch_id')
                ->whereNotNull('branch_id')
                ->get();

            foreach ($profiles as $p) {
                DB::table('profile_branch_access')->updateOrInsert(
                    ['profile_id' => $p->id, 'branch_id' => $p->branch_id],
                    [
                        'id' => (string) Str::uuid(),
                        'company_id' => $p->company_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });

        // 4) Enforce NOT NULL using raw SQL (no doctrine/dbal)
        DB::statement('ALTER TABLE branches ALTER COLUMN company_id SET NOT NULL');
        DB::statement('ALTER TABLE profiles ALTER COLUMN company_id SET NOT NULL');
    }

    public function down(): void
    {
        // Relax constraint (raw SQL)
        DB::statement('ALTER TABLE branches ALTER COLUMN company_id DROP NOT NULL');
        DB::statement('ALTER TABLE profiles ALTER COLUMN company_id DROP NOT NULL');

        // Do not delete companies automatically.
    }
};
