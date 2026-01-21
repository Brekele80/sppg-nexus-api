<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class PreferredLotInvalidException extends Exception
{
    public function __construct(
        public string $branchId,
        public string $inventoryItemId,
        public string $preferredLotId
    ) {
        parent::__construct('preferred_lot_id is not valid for this branch/item or has no remaining stock.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'preferred_lot_invalid',
                'message' => $this->getMessage(),
                'details' => [
                    'branch_id' => $this->branchId,
                    'inventory_item_id' => $this->inventoryItemId,
                    'preferred_lot_id' => $this->preferredLotId,
                ],
            ],
        ], 409);
    }
}
