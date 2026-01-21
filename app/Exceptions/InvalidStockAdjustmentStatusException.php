<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InvalidStockAdjustmentStatusException extends Exception
{
    public function __construct(
        public string $adjustmentId,
        public string $status,
        string $message = 'Invalid stock adjustment status for posting.'
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'invalid_status',
                'message' => $this->getMessage(),
                'details' => [
                    'stock_adjustment_id' => $this->adjustmentId,
                    'status' => $this->status,
                ],
            ],
        ], 409);
    }
}
