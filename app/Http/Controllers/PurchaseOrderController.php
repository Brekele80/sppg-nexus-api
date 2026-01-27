<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\AuthUser;
use App\Support\Audit;

class PurchaseOrderController extends Controller
{
    public function createFromApprovedRab(Request $request, string $rabId)
    {
        \Log::info('HIT createFromApprovedRab', ['rabId' => $rabId]);

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

        return DB::transaction(function () use ($request, $u, $companyId, $allowedBranchIds, $rabId, $data) {

            $rab = DB::table('rab_versions as rv')
                ->join('purchase_requests as pr', 'pr.id', '=', 'rv.purchase_request_id')
                ->join('branches as b', 'b.id', '=', 'pr.branch_id')
                ->where('rv.id', $rabId)
                ->where('b.company_id', $companyId)
                ->select('rv.*', 'pr.branch_id as pr_branch_id', 'pr.id as pr_id')
                ->first();

            if (!$rab) abort(404, 'RAB not found');
            if ($rab->status !== 'APPROVED') abort(422, 'RAB must be APPROVED to create PO');

            if (!in_array($rab->pr_branch_id, $allowedBranchIds, true)) {
                abort(403, 'Forbidden (no branch access)');
            }

            $supplierOk = DB::table('profiles as p')
                ->join('user_roles as ur', 'ur.user_id', '=', 'p.id')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('p.id', $data['supplier_id'])
                ->where('p.company_id', $companyId)
                ->where('r.code', 'SUPPLIER')
                ->exists();

            if (!$supplierOk) abort(422, 'supplier_id must be a SUPPLIER in this company');

            $existing = PurchaseOrder::where('rab_version_id', $rabId)->first();
            if ($existing) {
                // Return existing, DO NOT audit create
                return response()->json($existing->load('items'), 200);
            }

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

            Audit::log($request, 'create', 'purchase_orders', $po->id, [
                'branch_id' => $po->branch_id,
                'purchase_request_id' => $po->purchase_request_id,
                'rab_version_id' => $po->rab_version_id,
                'supplier_id' => $po->supplier_id,
                'po_number' => $po->po_number,
                'total' => (float) $po->total,
                'currency' => $po->currency,
                'items_count' => (int) $lines->count(),
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($po->load('items'), 201);
        });
    }

    public function sendToSupplier(Request $request, string $id)
    {
        \Log::info('HIT sendToSupplier', ['id' => $id]);

        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['PURCHASE_CABANG']);

        $companyId = AuthUser::companyId($request);
        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) abort(403, 'No branch access');

        return DB::transaction(function () use ($request, $u, $companyId, $allowedBranchIds, $id) {

            $po = PurchaseOrder::query()
                ->where('purchase_orders.id', $id)
                ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
                ->where('b.company_id', $companyId)
                ->select('purchase_orders.*')
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($po->branch_id, $allowedBranchIds, true)) abort(403, 'Forbidden (no branch access)');

            // Idempotent: already SENT -> return as-is, NO side effects
            if ($po->status === 'SENT') {
                return response()->json($po->load('items'), 200);
            }

            if ($po->status !== 'DRAFT') abort(422, 'PO must be DRAFT');

            $prev = $po->status;

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

            Audit::log($request, 'send', 'purchase_orders', $po->id, [
                'from' => $prev,
                'to' => $po->status,
                'sent_at' => (string) $po->sent_at,
                'supplier_id' => $po->supplier_id,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            // Notify supplier AFTER write (still inside tx is OK if your notifications trigger requires fields)
            // If you dispatch jobs, prefer afterCommit().
            // \App\Jobs\NotifySupplierNewPo::dispatch($po->id)->afterCommit();

            return response()->json($po->load('items'), 200);
        });
    }

    public function show(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        $companyId = AuthUser::companyId($request);

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
            return response()->json($po->load('items'), 200);
        }

        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds) || !in_array($po->branch_id, $allowedBranchIds, true)) {
            abort(403, 'Forbidden (no branch access)');
        }

        return response()->json($po->load('items'), 200);
    }
}
