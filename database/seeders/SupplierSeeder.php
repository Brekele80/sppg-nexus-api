<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $branchId = DB::table('branches')->value('id'); // use first branch for now

        // Idempotent upsert by code
        DB::table('suppliers')->updateOrInsert(
            ['code' => 'SUP-001'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Supplier Default',
                'email' => 'supplier1@user.com',
                'phone' => '0812-0000-0000',
                'branch_id' => $branchId,
                'is_active' => true,
                'meta' => json_encode(['note' => 'Seeded supplier']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
