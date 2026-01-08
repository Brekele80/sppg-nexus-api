<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class RolesAndPoliciesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['code' => 'CHEF', 'name' => 'Ahli Gizi / Chef'],
            ['code' => 'PURCHASE_CABANG', 'name' => 'Purchase Yayasan Cabang'],
            ['code' => 'KA_SPPG', 'name' => 'Ka. SPPG'],
            ['code' => 'ACCOUNTING', 'name' => 'Accounting'],
            ['code' => 'DC_ADMIN', 'name' => 'Distribution Center Admin'],
            ['code' => 'SUPPLIER', 'name' => 'Supplier'],
        ];

        foreach ($roles as $r) {
            Role::updateOrCreate(
                ['code' => $r['code']],
                ['id' => Role::where('code', $r['code'])->value('id') ?? (string) Str::uuid(), 'name' => $r['name']]
            );
        }

        // Policy + roles
        $policyId = (string) Str::uuid();

        $existing = DB::table('approval_policies')->where('code', 'RAB_APPROVAL')->first();
        if (!$existing) {
            DB::table('approval_policies')->insert([
                'id' => $policyId,
                'code' => 'RAB_APPROVAL',
                'name' => 'RAB Approval Policy (Parallel ANY_OF)',
                'mode' => 'ANY_OF',
                'min_approvals' => 1,
                'on_reject' => 'SOFT_REJECT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (['KA_SPPG', 'ACCOUNTING'] as $roleCode) {
                DB::table('approval_policy_roles')->insert([
                    'id' => (string) Str::uuid(),
                    'policy_id' => $policyId,
                    'role_code' => $roleCode,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
