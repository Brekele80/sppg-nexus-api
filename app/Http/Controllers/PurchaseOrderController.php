<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\AuthUser;

class PurchaseOrderController extends Controller
{
    // POST /api/rabs/{rabId}/po
    public function createFromApprovedRab(Request $request, string $rabId)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['PURCHASE_CABANG']);

        $companyId = AuthUser::companyId($request);
        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) {
            return response()->json(['error'=>['code'=>'no_branch_access','message'=>'No branch access']], 403);
        }

        $data = $request->validate([
            'supplier_id' => 'required|uuid',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($u, $companyId, $allowedBranchIds, $rabId, $data) {

            // 1) Load RAB (and PR) with company enforcement through branches
            $rab = DB::table('rab_versions as rv')
                ->join('purchase_requests as pr', 'pr.id', '=', 'rv.purchase_request_id')
                ->join('branches as b', 'b.id', '=', 'pr.branch_id')
                ->where('rv.id', $rabId)
                ->where('b.company_id', $companyId)
                ->select('rv.*', 'pr.branch_id as pr_branch_id', 'pr.id as pr_id')
                ->first();

            if (!$rab) abort(404, 'RAB not found');
            if ($rab->status !== 'APPROVED') abort(422, 'RAB must be APPROVED to create PO');

            // 2) Enforce branch access to that PR branch
            if (!in_array($rab->pr_branch_id, $allowedBranchIds, true)) {
                abort(403, 'Forbidden (no branch access)');
            }

            // 3) Supplier must be SUPPLIER role AND in same company
            $supplierOk = DB::table('profiles as p')
                ->join('user_roles as ur', 'ur.user_id', '=', 'p.id')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('p.id', $data['supplier_id'])
                ->where('p.company_id', $companyId)
                ->where('r.code', 'SUPPLIER')
                ->exists();

            if (!$supplierOk) abort(422, 'supplier_id must be a SUPPLIER in this company');

            // 4) Prevent duplicate PO for same approved rab
            $existing = PurchaseOrder::where('rab_version_id', $rabId)->first();
            if ($existing) {
                return response()->json($existing->load('items'), 200);
            }

            // 5) Create PO number
            $poNumber = 'PO-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

            $po = PurchaseOrder::create([
                'branch_id' => $rab->pr_branch_id,
                'purchase_request_id' => $rab->pr_id,
                'rab_version_id' => $rabId,
                'created_by' => $u->id,
                'supplier_id' => $data['supplier_id'],
                'po_number' => $poNumber,
                'status' => 'DRAFT',
                'currency' => $rab->currency ?? 'IDR',
                'subtotal' => $rab->subtotal ?? 0,
                'tax' => $rab->tax ?? 0,
                'total' => $rab->total ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);

            // 6) Copy line items from RAB to PO
            $lines = DB::table('rab_line_items')->where('rab_version_id', $rabId)->get();
            foreach ($lines as $ln) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'item_name' => $ln->item_name,
                    'unit' => $ln->unit,
                    'qty' => $ln->qty,
                    'unit_price' => $ln->unit_price,
                    'line_total' => $ln->line_total,
                ]);
            }

            // 7) Notify supplier AFTER commit
            \App\Jobs\NotifySupplierNewPo::dispatch($po->id)->afterCommit();

            return response()->json($po->load('items'), 201);
        });
    }

    // POST /api/pos/{id}/send
    public function sendToSupplier(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['PURCHASE_CABANG']);

        $companyId = AuthUser::companyId($request);
        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) abort(403, 'No branch access');

        // Enforce company by branch join
        $po = PurchaseOrder::query()
            ->where('purchase_orders.id', $id)
            ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
            ->where('b.company_id', $companyId)
            ->select('purchase_orders.*')
            ->firstOrFail();

        if (!in_array($po->branch_id, $allowedBranchIds, true)) abort(403, 'Forbidden (no branch access)');
        if ($po->status !== 'DRAFT') abort(422, 'PO must be DRAFT');

        $po->status = 'SENT';
        $po->sent_at = now();
        $po->save();

        DB::table('purchase_order_events')->insert([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $po->id,
            'actor_id' => $u->id,
            'event' => 'SENT',
            'message' => 'PO sent to supplier',
            'metadata' => json_encode([]),
            'created_at' => now(),
        ]);

        return response()->json($po->load('items'), 200);
    }

    // GET /api/pos/{id}
    public function show(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        $companyId = AuthUser::companyId($request);

        // Load PO with company enforcement
        $po = PurchaseOrder::query()
            ->where('purchase_orders.id', $id)
            ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
            ->where('b.company_id', $companyId)
            ->select('purchase_orders.*')
            ->firstOrFail();

        $roles = $u->roleCodes();

        $isSupplier   = in_array('SUPPLIER', $roles, true);
        $isAccounting = in_array('ACCOUNTING', $roles, true) || in_array('KA_SPPG', $roles, true);

        if ($isSupplier) {
            if ($po->supplier_id !== $u->id) abort(403, 'Forbidden (not your PO)');
            return response()->json($po->load('items'), 200);
        }

        if ($isAccounting) {
            // Company-wide, but still company-scoped (already enforced by join)
            return response()->json($po->load('items'), 200);
        }

        // Branch-scoped roles must have access to PO branch
        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds) || !in_array($po->branch_id, $allowedBranchIds, true)) {
            abort(403, 'Forbidden (no branch access)');
        }

        return response()->json($po->load('items'), 200);
    }
}
