<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Cross-tenant / cross-branch access violation.
 * IMPORTANT: Throw this inside DB::transaction to force rollback.
 */
class CrossBranchAccessException extends Exception
{
    public function __construct(
        public string $branchId,
        public ?string $companyId = null,
        public string $reason = 'Branch access denied'
    ) {
        parent::__construct($reason);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'cross_branch_access',
                'message' => $this->getMessage(),
                'details' => array_filter([
                    'branch_id' => $this->branchId,
                    'company_id' => $this->companyId,
                ], fn ($v) => $v !== null && $v !== ''),
            ],
        ], 403);
    }
}
