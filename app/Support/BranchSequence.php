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
                'id'         => (string) Str::uuid(),
                'branch_id'  => $branchId,
                'key'        => $key,
                'last_no'    => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('branch_sequences')
            ->where('branch_id', $branchId)
            ->where('key', $key)
            ->update([
                'last_no'    => DB::raw('last_no + 1'),
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
        // Create a deterministic signed 64-bit integer from sha1.
        // We take first 8 bytes and interpret as signed int64.
        $bytes = substr(sha1('branch_sequence:' . $key . ':' . $branchId, true), 0, 8);

        // Unpack to two 32-bit unsigned ints, then combine.
        $parts = unpack('Nhi/Nlo', $bytes);
        $hi = (int) $parts['hi'];
        $lo = (int) $parts['lo'];

        // Combine into 64-bit signed
        $lockKey = ($hi << 32) | $lo;

        DB::select('select pg_advisory_xact_lock(?)', [$lockKey]);
    }
}
