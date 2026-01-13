<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AuthUser;

class InventoryController extends Controller
{
    /**
     * GET /api/inventory
     * Dashboard modes:
     * - Default: branch-level (uses X-Branch-Id or user's branch_id)
     * - If header X-Dashboard-Scope=COMPANY: aggregate across allowed branches
     *
     * FIFO-native:
     * - source of truth for "on_hand" is inventory_items.on_hand (snapshot)
     */
    public function index(Request $request)
    {
        $companyId = AuthUser::requireCompanyContext($request);

        $scope = strtoupper((string) $request->header('X-Dashboard-Scope', 'BRANCH'));
        if (!in_array($scope, ['BRANCH', 'COMPANY'], true)) $scope = 'BRANCH';

        if ($scope === 'COMPANY') {
            $allowedBranchIds = AuthUser::allowedBranchIds($request);
            if (empty($allowedBranchIds)) {
                return response()->json([
                    'error' => ['code' => 'no_branch_access', 'message' => 'No branch access for this company']
                ], 403);
            }

            // 1️⃣ Per-branch snapshots
            $rows = DB::table('inventory_items as ii')
                ->join('branches as b', 'b.id', '=', 'ii.branch_id')
                ->where('b.company_id', $companyId)
                ->whereIn('ii.branch_id', $allowedBranchIds)
                ->select('ii.item_name', 'ii.unit', 'ii.branch_id', 'ii.on_hand')
                ->get();

            // 2️⃣ SQL-exact totals
            $totals = DB::table('inventory_items as ii')
                ->join('branches as b', 'b.id', '=', 'ii.branch_id')
                ->where('b.company_id', $companyId)
                ->whereIn('ii.branch_id', $allowedBranchIds)
                ->selectRaw("ii.item_name, ii.unit, SUM(ii.on_hand) AS total_on_hand")
                ->groupBy('ii.item_name', 'ii.unit')
                ->get()
                ->keyBy(fn($r) => $r->item_name.'||'.(string)($r->unit ?? ''));

            // 3️⃣ Merge into dashboard model
            $data = $rows
                ->groupBy(fn($r) => $r->item_name.'||'.(string)($r->unit ?? ''))
                ->map(function ($groupRows) use ($totals) {
                    $first = $groupRows->first();
                    $key   = $first->item_name.'||'.(string)($first->unit ?? '');

                    return [
                        'item_name'     => (string) $first->item_name,
                        'unit'          => $first->unit,
                        'total_on_hand' => (string) ($totals[$key]->total_on_hand ?? '0'),
                        'branches'      => $groupRows->map(fn($r) => [
                            'branch_id' => (string) $r->branch_id,
                            'on_hand'   => (string) $r->on_hand,
                        ])->values(),
                    ];
                })->values();

            return response()->json([
                'scope'      => 'COMPANY',
                'company_id' => $companyId,
                'branch_ids' => $allowedBranchIds,
                'data'       => $data,
            ]);
        }

        // Branch dashboard
        $branchId = AuthUser::requireBranchAccess($request);

        return response()->json([
            'scope' => 'BRANCH',
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'data' => DB::table('inventory_items as ii')
                ->where('ii.branch_id', $branchId)
                ->orderBy('ii.item_name')
                ->orderBy('ii.unit')
                ->get()
        ]);
    }

    /**
     * GET /api/inventory/movements
     * Dashboard modes:
     * - Default: branch-level movements
     * - X-Dashboard-Scope=COMPANY: movements across allowed branches
     *
     * FIFO-native:
     * - uses inventory_movements (signed qty)
     */
    public function movements(Request $request)
    {
        $companyId = AuthUser::requireCompanyContext($request);

        $scope = strtoupper((string) $request->header('X-Dashboard-Scope', 'BRANCH'));
        if (!in_array($scope, ['BRANCH', 'COMPANY'], true)) $scope = 'BRANCH';

        if ($scope === 'COMPANY') {
            $allowedBranchIds = AuthUser::allowedBranchIds($request);
            if (empty($allowedBranchIds)) {
                return response()->json([
                    'error' => ['code' => 'no_branch_access', 'message' => 'No branch access for this company']
                ], 403);
            }

            $rows = DB::table('inventory_movements as m')
                ->join('branches as b', 'b.id', '=', 'm.branch_id')
                ->leftJoin('inventory_items as ii', 'ii.id', '=', 'm.inventory_item_id')
                ->where('b.company_id', $companyId)
                ->whereIn('m.branch_id', $allowedBranchIds)
                ->orderByDesc('m.created_at')
                ->limit(200)
                ->select([
                    'm.id',
                    'm.branch_id',
                    'b.name as branch_name', // keep only if column exists
                    'm.inventory_item_id',
                    'ii.item_name',
                    'ii.unit',
                    'm.inventory_lot_id',
                    'm.direction',
                    'm.qty',
                    'm.source_type',
                    'm.source_id',
                    'm.notes',
                    'm.actor_id',
                    'm.created_at',
                ])
                ->get();

            return response()->json([
                'scope' => 'COMPANY',
                'company_id' => $companyId,
                'branch_ids' => $allowedBranchIds,
                'data' => $rows,
            ], 200);
        }

        // Default BRANCH scope
        $branchId = AuthUser::requireBranchAccess($request);

        $rows = DB::table('inventory_movements as m')
            ->join('branches as b', 'b.id', '=', 'm.branch_id')
            ->leftJoin('inventory_items as ii', 'ii.id', '=', 'm.inventory_item_id')
            ->where('b.company_id', $companyId)
            ->where('m.branch_id', $branchId)
            ->orderByDesc('m.created_at')
            ->limit(200)
            ->select([
                'm.id',
                'm.branch_id',
                'm.inventory_item_id',
                'ii.item_name',
                'ii.unit',
                'b.name as branch_name',
                'm.inventory_lot_id',
                'm.direction',
                'm.qty',
                'm.source_type',
                'm.source_id',
                'm.notes',
                'm.actor_id',
                'm.created_at',
            ])
            ->get();

        return response()->json([
            'scope' => 'BRANCH',
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'data' => $rows,
        ], 200);
    }

        /**
     * GET /api/inventory/lots
     * Query params (optional):
     * - q=keyword (matches item_name or lot_code)
     * - only_available=1 (only lots with remaining_qty > 0)
     * Dashboard modes:
     * - Default: branch-level (X-Branch-Id or user's branch_id)
     * - X-Dashboard-Scope=COMPANY: lots across allowed branches
     *
     * FIFO order:
     * - expiry_date NULLS LAST
     * - expiry_date ASC
     * - received_at ASC
     * - created_at ASC
     */
    public function lots(Request $request)
    {
        $companyId = AuthUser::requireCompanyContext($request);

        $scope = strtoupper((string) $request->header('X-Dashboard-Scope', 'BRANCH'));
        if (!in_array($scope, ['BRANCH', 'COMPANY'], true)) $scope = 'BRANCH';

        $onlyAvailable = (string) $request->query('only_available', '0') === '1';
        $q = trim((string) $request->query('q', ''));

        if ($scope === 'COMPANY') {
            $allowedBranchIds = AuthUser::allowedBranchIds($request);
            if (empty($allowedBranchIds)) {
                return response()->json([
                    'error' => ['code' => 'no_branch_access', 'message' => 'No branch access for this company']
                ], 403);
            }

            $query = DB::table('inventory_lots as l')
                ->join('branches as b', 'b.id', '=', 'l.branch_id')
                ->join('inventory_items as ii', 'ii.id', '=', 'l.inventory_item_id')
                ->where('b.company_id', $companyId)
                ->whereIn('l.branch_id', $allowedBranchIds);

            if ($onlyAvailable) {
                $query->where('l.remaining_qty', '>', 0);
            }

            if ($q !== '') {
                $query->where(function ($w) use ($q) {
                    $w->where('ii.item_name', 'ilike', "%{$q}%")
                      ->orWhere('l.lot_code', 'ilike', "%{$q}%");
                });
            }

            $rows = $query
                ->select([
                    'l.id',
                    'l.branch_id',
                    'l.inventory_item_id',
                    'ii.item_name',
                    'ii.unit',
                    'l.lot_code',
                    'l.expiry_date',
                    'l.received_qty',
                    'l.remaining_qty',
                    'l.unit_cost',
                    'l.currency',
                    'l.received_at',
                    'l.goods_receipt_id',
                    'l.goods_receipt_item_id',
                    'l.created_at',
                ])
                ->orderByRaw('CASE WHEN l.expiry_date IS NULL THEN 1 ELSE 0 END') // nulls last
                ->orderBy('l.expiry_date')
                ->orderBy('l.received_at')
                ->orderBy('l.created_at')
                ->limit(500)
                ->get();

            return response()->json([
                'scope' => 'COMPANY',
                'company_id' => $companyId,
                'branch_ids' => $allowedBranchIds,
                'filters' => ['q' => $q ?: null, 'only_available' => $onlyAvailable],
                'data' => $rows,
            ], 200);
        }

        // BRANCH scope
        $branchId = AuthUser::requireBranchAccess($request);

        $query = DB::table('inventory_lots as l')
            ->join('branches as b', 'b.id', '=', 'l.branch_id')
            ->join('inventory_items as ii', 'ii.id', '=', 'l.inventory_item_id')
            ->where('b.company_id', $companyId)
            ->where('l.branch_id', $branchId);

        if ($onlyAvailable) {
            $query->where('l.remaining_qty', '>', 0);
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('ii.item_name', 'ilike', "%{$q}%")
                  ->orWhere('l.lot_code', 'ilike', "%{$q}%");
            });
        }

        $rows = $query
            ->select([
                'l.id',
                'l.branch_id',
                'l.inventory_item_id',
                'ii.item_name',
                'ii.unit',
                'l.lot_code',
                'l.expiry_date',
                'l.received_qty',
                'l.remaining_qty',
                'l.unit_cost',
                'l.currency',
                'l.received_at',
                'l.goods_receipt_id',
                'l.goods_receipt_item_id',
                'l.created_at',
            ])
            ->orderByRaw('CASE WHEN l.expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('l.expiry_date')
            ->orderBy('l.received_at')
            ->orderBy('l.created_at')
            ->limit(500)
            ->get();

        return response()->json([
            'scope' => 'BRANCH',
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'filters' => ['q' => $q ?: null, 'only_available' => $onlyAvailable],
            'data' => $rows,
        ], 200);
    }

    /**
     * GET /api/inventory/items/{itemId}/lots
     * Same as /inventory/lots but filtered by inventory_item_id.
     */
    public function lotsByItem(Request $request, string $itemId)
    {
        $companyId = AuthUser::requireCompanyContext($request);

        $scope = strtoupper((string) $request->header('X-Dashboard-Scope', 'BRANCH'));
        if (!in_array($scope, ['BRANCH', 'COMPANY'], true)) $scope = 'BRANCH';

        $onlyAvailable = (string) $request->query('only_available', '1') === '1'; // default true for item drilldown

        if ($scope === 'COMPANY') {
            $allowedBranchIds = AuthUser::allowedBranchIds($request);
            if (empty($allowedBranchIds)) {
                return response()->json([
                    'error' => ['code' => 'no_branch_access', 'message' => 'No branch access for this company']
                ], 403);
            }

            $rows = DB::table('inventory_lots as l')
                ->join('branches as b', 'b.id', '=', 'l.branch_id')
                ->join('inventory_items as ii', 'ii.id', '=', 'l.inventory_item_id')
                ->where('b.company_id', $companyId)
                ->whereIn('l.branch_id', $allowedBranchIds)
                ->where('l.inventory_item_id', $itemId)
                ->when($onlyAvailable, fn($q) => $q->where('l.remaining_qty', '>', 0))
                ->select([
                    'l.id',
                    'l.branch_id',
                    'l.inventory_item_id',
                    'ii.item_name',
                    'ii.unit',
                    'l.lot_code',
                    'l.expiry_date',
                    'l.received_qty',
                    'l.remaining_qty',
                    'l.unit_cost',
                    'l.currency',
                    'l.received_at',
                    'l.goods_receipt_id',
                    'l.goods_receipt_item_id',
                    'l.created_at',
                ])
                ->orderByRaw('CASE WHEN l.expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('l.expiry_date')
                ->orderBy('l.received_at')
                ->orderBy('l.created_at')
                ->limit(500)
                ->get();

            return response()->json([
                'scope' => 'COMPANY',
                'company_id' => $companyId,
                'branch_ids' => $allowedBranchIds,
                'inventory_item_id' => $itemId,
                'filters' => ['only_available' => $onlyAvailable],
                'data' => $rows,
            ], 200);
        }

        // BRANCH scope
        $branchId = AuthUser::requireBranchAccess($request);

        $rows = DB::table('inventory_lots as l')
            ->join('branches as b', 'b.id', '=', 'l.branch_id')
            ->join('inventory_items as ii', 'ii.id', '=', 'l.inventory_item_id')
            ->where('b.company_id', $companyId)
            ->where('l.branch_id', $branchId)
            ->where('l.inventory_item_id', $itemId)
            ->when($onlyAvailable, fn($q) => $q->where('l.remaining_qty', '>', 0))
            ->select([
                'l.id',
                'l.branch_id',
                'l.inventory_item_id',
                'ii.item_name',
                'ii.unit',
                'l.lot_code',
                'l.expiry_date',
                'l.received_qty',
                'l.remaining_qty',
                'l.unit_cost',
                'l.currency',
                'l.received_at',
                'l.goods_receipt_id',
                'l.goods_receipt_item_id',
                'l.created_at',
            ])
            ->orderByRaw('CASE WHEN l.expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('l.expiry_date')
            ->orderBy('l.received_at')
            ->orderBy('l.created_at')
            ->limit(500)
            ->get();

        return response()->json([
            'scope' => 'BRANCH',
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'inventory_item_id' => $itemId,
            'filters' => ['only_available' => $onlyAvailable],
            'data' => $rows,
        ], 200);
    }
}
