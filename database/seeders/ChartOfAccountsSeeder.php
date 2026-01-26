<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Seed a minimal, stable COA per company.
     * Idempotent: uses (company_id, code) unique constraint.
     */
    public function run(): void
    {
        $now = now();

        $companies = DB::table('companies')->select(['id'])->get();
        if ($companies->isEmpty()) {
            $this->command?->warn('No companies found. Skipping COA seed.');
            return;
        }

        // Minimal production-grade COA baseline (expand later, keep codes stable)
        $coa = [
            // ASSET
            ['code' => '1100', 'name' => 'Cash / Bank',                 'type' => 'ASSET',     'normal_balance' => 'D'],
            ['code' => '1200', 'name' => 'Accounts Receivable',         'type' => 'ASSET',     'normal_balance' => 'D'],
            ['code' => '1300', 'name' => 'Inventory - Raw Materials',   'type' => 'ASSET',     'normal_balance' => 'D'],
            ['code' => '1310', 'name' => 'Inventory - Packaging',       'type' => 'ASSET',     'normal_balance' => 'D'],

            // LIABILITY
            ['code' => '2100', 'name' => 'Accounts Payable',            'type' => 'LIABILITY', 'normal_balance' => 'C'],
            ['code' => '2200', 'name' => 'Accrued Expenses',            'type' => 'LIABILITY', 'normal_balance' => 'C'],

            // EQUITY
            ['code' => '3100', 'name' => 'Retained Earnings',           'type' => 'EQUITY',    'normal_balance' => 'C'],

            // REVENUE (for later)
            ['code' => '4100', 'name' => 'Revenue',                     'type' => 'REVENUE',   'normal_balance' => 'C'],

            // EXPENSE / COGS
            ['code' => '5100', 'name' => 'COGS - Raw Materials',        'type' => 'EXPENSE',   'normal_balance' => 'D'],
            ['code' => '5200', 'name' => 'COGS - Packaging',            'type' => 'EXPENSE',   'normal_balance' => 'D'],
            ['code' => '6100', 'name' => 'Inventory Shrinkage / Write-off', 'type' => 'EXPENSE','normal_balance' => 'D'],
        ];

        foreach ($companies as $c) {
            $companyId = (string) $c->id;

            foreach ($coa as $row) {
                // If exists, keep id stable; else create a new UUID.
                $existing = DB::table('chart_of_accounts')
                    ->where('company_id', $companyId)
                    ->where('code', $row['code'])
                    ->select(['id'])
                    ->first();

                $id = $existing ? (string) $existing->id : (string) Str::uuid();

                DB::table('chart_of_accounts')->updateOrInsert(
                    ['company_id' => $companyId, 'code' => $row['code']],
                    [
                        'id'             => $id,
                        'parent_id'      => null,
                        'name'           => $row['name'],
                        'type'           => $row['type'],
                        'normal_balance' => $row['normal_balance'],
                        'is_active'      => true,
                        'meta'           => null,
                        'created_at'     => $existing ? DB::raw('created_at') : $now,
                        'updated_at'     => $now,
                    ]
                );
            }
        }
    }
}
