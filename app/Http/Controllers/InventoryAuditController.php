<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryAuditorService;
use App\Support\AuthUser;
use Illuminate\Http\Request;

class InventoryAuditController extends Controller
{
    /**
     * POST /api/inventory/audit/on-hand
     *
     * Body:
     * {
     *   "branch_id": "uuid",
     *   "inventory_item_id": "uuid|null",
     *   "fix": false
     * }
     *
     * - fix=false => read-only audit
     * - fix=true  => recompute and update inventory_items.on_hand from lots (inside TX)
     */
    public function auditOnHand(Request $request, InventoryAuditorService $svc)
    {
        // Role + company context is enforced again in service, but keep controller consistent.
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);
        AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'inventory_item_id' => 'nullable|uuid',
            'fix' => 'sometimes|boolean',
        ]);

        $branchId = (string)$data['branch_id'];
        $inventoryItemId = isset($data['inventory_item_id']) ? (string)$data['inventory_item_id'] : null;
        $fix = (bool)($data['fix'] ?? false);

        $result = $svc->auditOnHand($request, $branchId, $inventoryItemId, $fix);

        return response()->json($result, 200);
    }
}
