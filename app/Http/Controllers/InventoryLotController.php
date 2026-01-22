<?php

namespace App\Http\Controllers;

use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryLotController extends Controller
{
    /**
     * GET /api/dc/inventory-lots
     *
     * Query params:
     * - branch_id (required)
     * - inventory_item_id (required)
     * - only_available (optional boolean, default true): remaining_qty > 0
     * - limit (optional 1..200)
     *
     * Sorting (FIFO-relevant):
     * - received_at asc NULLS LAST
     * - id asc
     */
    public function index(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'branch_id'         => 'required|uuid',
            'inventory_item_id' => 'required|uuid',
            'only_available'    => 'nullable|boolean',
            'limit'             => 'nullable|integer|min:1|max:200',
        ]);

        $branchId = (string)$data['branch_id'];
        $itemId = (string)$data['inventory_item_id'];
        $onlyAvailable = array_key_exists('only_available', $data) ? (bool)$data['only_available'] : true;
        $limit = (int)($data['limit'] ?? 200);

        // Allowed branch check
        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array($branchId, $allowed, true)) {
            return response()->json([
                'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']
            ], 403);
        }

        // Defense-in-depth: branch belongs to company
        $branchOk = DB::table('branches')
            ->where('id', $branchId)
            ->where('company_id', (string)$companyId)
            ->exists();

        if (!$branchOk) {
            return response()->json([
                'error' => ['code' => 'invalid_branch', 'message' => 'Branch not found in company']
            ], 422);
        }

        // Ensure inventory_item belongs to branch (prevents cross-branch leakage)
        $itemOk = DB::table('inventory_items')
            ->where('id', $itemId)
            ->where('branch_id', $branchId)
            ->exists();

        if (!$itemOk) {
            return response()->json([
                'error' => ['code' => 'invalid_inventory_item', 'message' => 'inventory_item_id not found in this branch']
            ], 422);
        }

        $query = DB::table('inventory_lots')
            ->where('branch_id', $branchId)
            ->where('inventory_item_id', $itemId);

        if ($onlyAvailable) {
            $query->where('remaining_qty', '>', 0);
        }

        $rows = $query
            ->orderByRaw('CASE WHEN received_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('received_at')
            ->orderBy('id')
            ->limit($limit)
            ->get([
                'id',
                'branch_id',
                'inventory_item_id',
                'lot_code',
                'expiry_date',
                'received_qty',
                'remaining_qty',
                'unit_cost',
                'currency',
                'received_at',
                'created_at',
                'updated_at',
            ]);

        return response()->json([
            'company_id' => (string)$companyId,
            'branch_id'  => $branchId,
            'inventory_item_id' => $itemId,
            'filters' => [
                'only_available' => $onlyAvailable,
                'limit' => $limit,
            ],
            'data' => $rows,
        ]);
    }
}
