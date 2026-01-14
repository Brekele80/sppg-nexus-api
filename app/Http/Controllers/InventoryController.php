<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use App\Support\AuthUser;
use App\Support\Audit;

use App\Models\InventoryMovement;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;

class InventoryController extends Controller
{
    /**
     * GET /api/inventory
     * (Your existing read-only implementation, unchanged)
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

            $rows = DB::table('inventory_items as ii')
                ->join('branches as b', 'b.id', '=', 'ii.branch_id')
                ->where('b.company_id', $companyId)
                ->whereIn('ii.branch_id', $allowedBranchIds)
                ->select('ii.item_name', 'ii.unit', 'ii.branch_id', 'ii.on_hand')
                ->get();

            $totals = DB::table('inventory_items as ii')
                ->join('branches as b', 'b.id', '=', 'ii.branch_id')
                ->where('b.company_id', $companyId)
                ->whereIn('ii.branch_id', $allowedBranchIds)
                ->selectRaw("ii.item_name, ii.unit, SUM(ii.on_hand) AS total_on_hand")
                ->groupBy('ii.item_name', 'ii.unit')
                ->get()
                ->keyBy(fn($r) => $r->item_name.'||'.(string)($r->unit ?? ''));

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
     * (Your existing implementation, unchanged)
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
                    'm.inventory_item_id',
                    'ii.item_name',
                    'ii.unit',
                    DB::raw('b.name as branch_name'),
                    'm.inventory_lot_id',
                    'm.type',
                    DB::raw("CASE WHEN m.type LIKE '%_IN' THEN 'IN' ELSE 'OUT' END AS direction"),
                    'm.qty',
                    'm.source_type',
                    'm.source_id',
                    'm.note',
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
                DB::raw('b.name as branch_name'),
                'm.inventory_lot_id',
                'm.type',
                DB::raw("CASE WHEN m.type LIKE '%_IN' THEN 'IN' ELSE 'OUT' END AS direction"),
                'm.qty',
                'm.source_type',
                'm.source_id',
                'm.note',
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
     * (Your existing implementation, unchanged)
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

            if ($onlyAvailable) $query->where('l.remaining_qty', '>', 0);

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
                'scope' => 'COMPANY',
                'company_id' => $companyId,
                'branch_ids' => $allowedBranchIds,
                'filters' => ['q' => $q ?: null, 'only_available' => $onlyAvailable],
                'data' => $rows,
            ], 200);
        }

        $branchId = AuthUser::requireBranchAccess($request);

        $query = DB::table('inventory_lots as l')
            ->join('branches as b', 'b.id', '=', 'l.branch_id')
            ->join('inventory_items as ii', 'ii.id', '=', 'l.inventory_item_id')
            ->where('b.company_id', $companyId)
            ->where('l.branch_id', $branchId);

        if ($onlyAvailable) $query->where('l.remaining_qty', '>', 0);

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
     * (Your existing implementation, unchanged)
     */
    public function lotsByItem(Request $request, string $itemId)
    {
        $companyId = AuthUser::requireCompanyContext($request);

        $scope = strtoupper((string) $request->header('X-Dashboard-Scope', 'BRANCH'));
        if (!in_array($scope, ['BRANCH', 'COMPANY'], true)) $scope = 'BRANCH';

        $onlyAvailable = (string) $request->query('only_available', '1') === '1';

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

    /**
     * POST /api/dc/adjustments
     * Body:
     * {
     *   "branch_id": "uuid",
     *   "type": "INCREASE|DECREASE",
     *   "notes": "optional",
     *   "items": [
     *     {"item_name":"Beras","unit":"kg","qty_delta": 5, "remarks":"optional"}
     *   ]
     * }
     */
    public function adjust(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'type' => 'required|string|in:INCREASE,DECREASE,increase,decrease',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.qty_delta' => 'required|numeric|min:0.001',
            'items.*.remarks' => 'nullable|string|max:2000',
        ]);

        $branchId = (string) $data['branch_id'];

        // 1) Branch must be inside company
        $branchOk = DB::table('branches')
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->exists();
        if (!$branchOk) {
            return response()->json(['error'=>['code'=>'branch_invalid','message'=>'Branch not found in company']], 422);
        }

        // 2) User must have access to this branch
        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array($branchId, $allowed, true)) {
            return response()->json(['error'=>['code'=>'branch_forbidden','message'=>'No access to this branch']], 403);
        }

        $type = strtoupper((string) $data['type']);
        $sign = $type === 'INCREASE' ? 1.0 : -1.0;

        return DB::transaction(function () use ($request, $u, $companyId, $branchId, $type, $sign, $data) {

            // 3) Create adjustment header
            $adj = StockAdjustment::create([
                'id' => (string) Str::uuid(),
                'branch_id' => $branchId,
                'type' => $type,
                'status' => 'POSTED',
                'notes' => $data['notes'] ?? null,
                'created_by' => $u->id,
                'posted_at' => now(),
            ]);

            $linesForAudit = [];

            foreach ($data['items'] as $it) {
                $itemName = (string) $it['item_name'];
                $unit     = $it['unit'] ?? null;
                $deltaAbs = (float) $it['qty_delta'];
                $deltaSigned = $sign * $deltaAbs;

                // 4) Ensure inventory_items exists
                // NOTE: This assumes inventory_items has (id uuid, branch_id uuid, item_name, unit, on_hand).
                $invItem = DB::table('inventory_items')
                    ->where('branch_id', $branchId)
                    ->where('item_name', $itemName)
                    ->where(function ($q) use ($unit) {
                        if ($unit === null) $q->whereNull('unit');
                        else $q->where('unit', $unit);
                    })
                    ->lockForUpdate()
                    ->first();

                if (!$invItem) {
                    $invItemId = (string) Str::uuid();

                    DB::table('inventory_items')->insert([
                        'id' => $invItemId,
                        'branch_id' => $branchId,
                        'item_name' => $itemName,
                        'unit' => $unit,
                        'on_hand' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $invItem = DB::table('inventory_items')
                        ->where('id', $invItemId)
                        ->lockForUpdate()
                        ->first();
                }

                $beforeOnHand = (float) ($invItem->on_hand ?? 0);

                // 5) Apply snapshot update (no negative protection for DECREASE? we enforce)
                $afterOnHand = $beforeOnHand + $deltaSigned;
                if ($afterOnHand < 0) {
                    return response()->json([
                        'error' => [
                            'code' => 'insufficient_stock',
                            'message' => "Cannot decrease below zero for item: {$itemName}"
                        ]
                    ], 409);
                }

                DB::table('inventory_items')
                    ->where('id', $invItem->id)
                    ->update([
                        'on_hand' => $afterOnHand,
                        'updated_at' => now(),
                    ]);

                // 6) Create adjustment item row
                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adj->id,
                    'item_name' => $itemName,
                    'unit' => $unit,
                    'qty_delta' => $deltaSigned, // store signed so it matches movement direction
                    'remarks' => $it['remarks'] ?? null,
                ]);

                // 7) Create movement row (signed qty)
                InventoryMovement::create([
                    'id' => (string) Str::uuid(),
                    'branch_id' => $branchId,
                    'inventory_item_id' => (string) $invItem->id,

                    'type' => 'ADJUSTMENT',
                    'qty' => $deltaSigned,

                    'inventory_lot_id' => null,
                    'source_type' => 'ADJUSTMENT',
                    'source_id' => $adj->id,

                    'ref_type' => 'stock_adjustments',
                    'ref_id' => $adj->id,

                    'actor_id' => $u->id,
                    'note' => $type . ' via stock adjustment',
                ]);

                $linesForAudit[] = [
                    'inventory_item_id' => (string) $invItem->id,
                    'item_name' => $itemName,
                    'unit' => $unit,
                    'qty_delta' => $deltaSigned,
                    'on_hand_before' => $beforeOnHand,
                    'on_hand_after' => $afterOnHand,
                    'remarks' => $it['remarks'] ?? null,
                ];
            }

            // 8) Audit ledger
            Audit::log($request, 'adjust', 'stock_adjustments', $adj->id, [
                'branch_id' => $branchId,
                'type' => $type,
                'status' => 'POSTED',
                'notes' => $data['notes'] ?? null,
                'posted_at' => (string) $adj->posted_at,
                'lines' => $linesForAudit,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json([
                'id' => (string) $adj->id,
                'branch_id' => $branchId,
                'type' => $type,
                'status' => 'POSTED',
                'posted_at' => $adj->posted_at,
                'items' => $linesForAudit,
            ], 201);
        });
    }
}
