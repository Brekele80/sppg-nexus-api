<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Domain error: insufficient stock to fulfill FIFO consumption.
 * IMPORTANT: Throw this inside DB::transaction to force rollback.
 */
class InsufficientStockException extends Exception
{
    public function __construct(
        public string $branchId,
        public string $inventoryItemId,
        public string $requestedQty,
        public string $availableQty,
        public string $reason = 'Insufficient stock for FIFO consumption'
    ) {
        parent::__construct($reason);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'insufficient_stock',
                'message' => $this->getMessage(),
                'details' => [
                    'branch_id' => $this->branchId,
                    'inventory_item_id' => $this->inventoryItemId,
                    'requested_qty' => $this->requestedQty,
                    'available_qty' => $this->availableQty,
                ],
            ],
        ], 409);
    }
}
