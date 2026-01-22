<?php

namespace App\Http\Controllers;

use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryItemController extends Controller
{
    /**
     * GET /api/dc/inventory-items
     *
     * Query params:
     * - branch_id (required): uuid
     * - q (optional): search by item_name/unit
     * - limit (optional): 1..200
     *
     * Multi-tenant safe:
     * - company context enforced
     * - branch belongs to company
     * - user has branch access
     */
    public function index(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'q'         => 'nullable|string|max:200',
            'limit'     => 'nullable|integer|min:1|max:200',
        ]);

        $branchId = (string)$data['branch_id'];
        $q = isset($data['q']) ? trim((string)$data['q']) : '';
        $limit = (int)($data['limit'] ?? 50);

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

        $query = DB::table('inventory_items')
            ->where('branch_id', $branchId)
            ->orderBy('item_name')
            ->orderByRaw('CASE WHEN unit IS NULL THEN 1 ELSE 0 END')
            ->orderBy('unit');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('item_name', 'ilike', "%{$q}%")
                  ->orWhere('unit', 'ilike', "%{$q}%");
            });
        }

        $rows = $query->limit($limit)->get([
            'id',
            'branch_id',
            'item_name',
            'unit',
            'on_hand',
            'created_at',
            'updated_at',
        ]);

        return response()->json([
            'company_id' => (string)$companyId,
            'branch_id'  => $branchId,
            'filters' => [
                'q' => $q ?: null,
                'limit' => $limit,
            ],
            'data' => $rows,
        ]);
    }
}
