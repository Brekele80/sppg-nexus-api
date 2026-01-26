<?php

namespace App\Domain\Accounting;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountingCoaResolver
{
    /**
     * Resolve account_id by (company_id, code). Fail hard if missing.
     */
    public static function accountId(string $companyId, string $code): string
    {
        $row = DB::table('chart_of_accounts')
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->select(['id'])
            ->first();

        if (!$row) {
            throw new HttpException(500, "COA code {$code} not found for company");
        }

        return (string) $row->id;
    }
}
