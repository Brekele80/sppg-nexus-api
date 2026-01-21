<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class StockAdjustmentItemMissingInventoryItemException extends Exception
{
    public function __construct(
        public string $adjustmentId,
        public int $lineNo
    ) {
        parent::__construct('stock_adjustment_items.inventory_item_id is required for posting.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'missing_inventory_item_id',
                'message' => $this->getMessage(),
                'details' => [
                    'stock_adjustment_id' => $this->adjustmentId,
                    'line_no' => $this->lineNo,
                ],
            ],
        ], 422);
    }
}
