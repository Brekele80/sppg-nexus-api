<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BranchSequence
{
    /**
     * Get next number for a branch-scoped key using:
     * - pg_advisory_xact_lock (per branch+key)
     * - atomic update of branch_sequences.last_no
     *
     * Must be called INSIDE an active DB transaction.
     */
    public static function next(string $branchId, string $key): int
    {
        self::advisoryLock($branchId, $key);

        // Ensure row exists (idempotent under lock).
        $row = DB::table('branch_sequences')
            ->where('branch_id', $branchId)
            ->where('key', $key)
            ->first();

        if (!$row) {
            DB::table('branch_sequences')->insert([
                'id' => (string) Str::uuid(),
                'branch_id' => $branchId,
                'key' => $key,
                'last_no' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Atomic increment, then read last_no.
        DB::table('branch_sequences')
            ->where('branch_id', $branchId)
            ->where('key', $key)
            ->update([
                'last_no' => DB::raw('last_no + 1'),
                'updated_at' => now(),
            ]);

        $n = DB::table('branch_sequences')
            ->where('branch_id', $branchId)
            ->where('key', $key)
            ->value('last_no');

        return (int) $n;
    }

    private static function advisoryLock(string $branchId, string $key): void
    {
        // stable bigint key from md5
        $hex = substr(md5('branch_sequence:' . $key . ':' . $branchId), 0, 15);
        $lockKey = hexdec($hex);
        DB::select('select pg_advisory_xact_lock(?)', [$lockKey]);
    }
}
