<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryIntegrityAuditService;
use App\Support\AuthUser;
use Illuminate\Http\Request;

class InventoryIntegrityAuditController extends Controller
{
    /**
     * POST /api/inventory/audit/integrity
     *
     * Body:
     * {
     *   "branch_id": "uuid",
     *   "inventory_item_id": "uuid|null",
     *   "fix": false
     * }
     *
     * - fix=false => read-only audit (ACCOUNTING/KA_SPPG/DC_ADMIN)
     * - fix=true  => DC_ADMIN only; recompute inventory_items.on_hand from lots (truth)
     */
    public function run(Request $request, InventoryIntegrityAuditService $svc)
    {
        // Keep controller consistent; service re-enforces.
        $u = AuthUser::get($request);
        AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'inventory_item_id' => 'nullable|uuid',
            'fix' => 'sometimes|boolean',
        ]);

        $branchId = (string) $data['branch_id'];
        $inventoryItemId = isset($data['inventory_item_id']) ? (string) $data['inventory_item_id'] : null;
        $fix = (bool) ($data['fix'] ?? false);

        // role gate (optional early gate; service is final authority)
        if ($fix) {
            AuthUser::requireRole($u, ['DC_ADMIN']);
        } else {
            AuthUser::requireRole($u, ['DC_ADMIN', 'ACCOUNTING', 'KA_SPPG']);
        }

        $result = $svc->run($request, $branchId, $inventoryItemId, $fix);
        return response()->json($result, 200);
    }
}
