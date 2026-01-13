<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditCompanyConstraints extends Command
{
    protected $signature = 'company:audit {--fix : Fix mismatched profile_branch_access.company_id and null company_id rows}';
    protected $description = 'Audit company_id constraints and data consistency across branches, profiles, and profile_branch_access';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $this->info('Auditing company scoping...');

        // 1) Check NULL company_id (should be 0 after enforcement)
        $nullBranches = (int) DB::table('branches')->whereNull('company_id')->count();
        $nullProfiles = (int) DB::table('profiles')->whereNull('company_id')->count();

        $this->line("branches.company_id NULL count: {$nullBranches}");
        $this->line("profiles.company_id NULL count: {$nullProfiles}");

        // 2) Check profile_branch_access mismatch vs profiles.company_id
        $pbaExists = DB::getSchemaBuilder()->hasTable('profile_branch_access');

        if ($pbaExists) {
            $mismatch = DB::selectOne("
                SELECT COUNT(*)::int AS c
                FROM profile_branch_access pba
                JOIN profiles p ON pba.profile_id = p.id
                WHERE pba.company_id IS NULL OR pba.company_id <> p.company_id
            ");
            $mismatchCount = (int) ($mismatch->c ?? 0);

            $this->line("profile_branch_access.company_id mismatches: {$mismatchCount}");
        } else {
            $this->warn('profile_branch_access table not found; skipping mismatch check.');
            $mismatchCount = 0;
        }

        if ($fix) {
            $this->warn('Running FIX operations...');

            // Backfill company_id using DEFAULT company if available; otherwise pick first company
            $default = DB::table('companies')->where('code', 'DEFAULT')->first();
            $fallbackCompanyId = $default ? $default->id : DB::table('companies')->value('id');

            if (!$fallbackCompanyId) {
                $this->error('No company found; cannot fix. Create a company first.');
                return self::FAILURE;
            }

            DB::transaction(function () use ($fallbackCompanyId, $pbaExists) {
                DB::table('branches')->whereNull('company_id')->update([
                    'company_id' => $fallbackCompanyId,
                    'updated_at' => now(),
                ]);

                DB::table('profiles')->whereNull('company_id')->update([
                    'company_id' => $fallbackCompanyId,
                    'updated_at' => now(),
                ]);

                if ($pbaExists) {
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

            $this->info('Fix completed. Re-run `php artisan company:audit` to confirm.');
        } else {
            if ($nullBranches > 0 || $nullProfiles > 0 || ($pbaExists && $mismatchCount > 0)) {
                $this->warn('Audit found issues. Run: php artisan company:audit --fix');
                return self::FAILURE;
            }
        }

        $this->info('Audit passed.');
        return self::SUCCESS;
    }
}
