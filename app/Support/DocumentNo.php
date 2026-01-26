<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DocumentNo
{
    /**
     * Generate next doc number: {PREFIX}-{YYYYMM}-{000001}
     *
     * @param string $companyId UUID
     * @param string $table     table name, e.g. "stock_adjustments"
     * @param string $prefix    e.g. "SA"
     * @param string $column    doc no column name in $table (default "adjustment_no")
     */
    public static function next(
        string $companyId,
        string $table,
        string $prefix,
        string $column = 'adjustment_no'
    ): string {
        // Defensive: only allow safe identifier chars for column/table
        // (You control these in code; this prevents accidental injection.)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            abort(500, 'Invalid table identifier');
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            abort(500, 'Invalid column identifier');
        }

        $period = now()->format('Ym'); // 202601
        $counterKey = "{$table}:{$prefix}:{$period}";

        return DB::transaction(function () use ($companyId, $table, $prefix, $column, $period, $counterKey) {

            // Lock counter row (if exists)
            $row = DB::table('document_counters')
                ->where('company_id', $companyId)
                ->where('key', $counterKey)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                $like = "{$prefix}-{$period}-%";

                // Note: identifiers cannot be bound, so we validated $column above.
                $max = DB::table($table)
                    ->where('company_id', $companyId)
                    ->where($column, 'like', $like)
                    ->selectRaw("max((right({$column}, 6))::int) as m")
                    ->value('m');

                $start = $max ? ((int) $max + 1) : 1;

                // next_no stores the NEXT number to be issued after we allocate $start
                DB::table('document_counters')->insert([
                    'company_id' => $companyId,
                    'key'        => $counterKey,
                    'next_no'    => $start + 1,
                    'updated_at' => now(),
                ]);

                return self::format($prefix, $period, $start);
            }

            $n = (int) $row->next_no;

            DB::table('document_counters')
                ->where('company_id', $companyId)
                ->where('key', $counterKey)
                ->update([
                    'next_no'    => $n + 1,
                    'updated_at' => now(),
                ]);

            return self::format($prefix, $period, $n);
        });
    }

    private static function format(string $prefix, string $period, int $n): string
    {
        return sprintf('%s-%s-%06d', $prefix, $period, $n);
    }
}
