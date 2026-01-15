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
        // DC_ADMIN only (you already enforce via middleware, but keep safe)
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        // company scoped
        $companyId = AuthUser::requireCompanyContext($request);

        // Validate using the NEW document create contract
        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'reason'    => 'nullable|string|max:2000',
            'notes'     => 'nullable|string|max:2000',

            // Backward compatibility: accept old field names if present
            // old v1: type=INCREASE|DECREASE and items[].qty_delta
            'type'      => 'nullable|string',
            'items'     => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.unit'      => 'nullable|string|max:30',

            // v2 expects qty, direction. We'll map.
            'items.*.qty'       => 'nullable|numeric|min:0.001',
            'items.*.direction' => 'nullable|string',
            'items.*.qty_delta' => 'nullable|numeric|min:0.001',
            'items.*.remarks'   => 'nullable|string|max:2000',

            // optional v2 fields
            'items.*.expiry_date' => 'nullable|date',
            'items.*.unit_cost'   => 'nullable|numeric|min:0',
            'items.*.currency'    => 'nullable|string|max:10',
            'items.*.received_at' => 'nullable|date',
            'items.*.preferred_lot_id' => 'nullable|uuid',
        ]);

        // Map v1 -> v2
        $mappedItems = [];
        foreach ($data['items'] as $it) {
            $itemName = (string)$it['item_name'];
            $unit = $it['unit'] ?? null;

            // Determine direction + qty
            $direction = null;
            $qty = null;

            if (!empty($it['direction']) && !empty($it['qty'])) {
                $direction = strtoupper((string)$it['direction']);
                $qty = (float)$it['qty'];
            } else {
                // v1 style
                $type = strtoupper((string)($data['type'] ?? 'INCREASE'));
                $direction = ($type === 'DECREASE') ? 'OUT' : 'IN';
                $qty = (float)($it['qty_delta'] ?? 0);
            }

            if (!in_array($direction, ['IN','OUT'], true) || $qty <= 0) {
                return response()->json([
                    'error' => ['code' => 'invalid_payload', 'message' => 'Each item must resolve to direction IN/OUT and qty > 0']
                ], 422);
            }

            $mappedItems[] = [
                'direction' => $direction,
                'item_name' => $itemName,
                'unit'      => $unit,
                'qty'       => $qty,
                'expiry_date' => $it['expiry_date'] ?? null,
                'unit_cost'   => (float)($it['unit_cost'] ?? 0),
                'currency'    => (string)($it['currency'] ?? 'IDR'),
                'received_at' => $it['received_at'] ?? null,
                'preferred_lot_id' => $it['preferred_lot_id'] ?? null,
                'remarks'     => $it['remarks'] ?? null,
            ];
        }

        // Use the document workflow internally
        $ctrl = app(\App\Http\Controllers\StockAdjustmentController::class);

        // 1) create
        $createReq = $request->replace([
            'branch_id' => $data['branch_id'],
            'reason'    => $data['reason'] ?? null,
            'notes'     => $data['notes'] ?? null,
            'items'     => $mappedItems,
        ]);

        $createdResp = $ctrl->create($createReq);
        $created = $createdResp->getData(true);
        $docId = $created['id'] ?? null;

        if (!$docId) {
            return $createdResp; // bubble up error
        }

        // 2) submit
        $ctrl->submit($request, $docId);

        // 3) approve
        $ctrl->approve($request, $docId);

        // 4) post (returns posted summary)
        return $ctrl->post($request, $docId, app(\App\Domain\Inventory\StockAdjustmentPostingService::class));
    }
}
