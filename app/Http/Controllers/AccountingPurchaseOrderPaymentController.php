<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Support\AuthUser;
use App\Support\Audit;

class AccountingPurchaseOrderPaymentController extends Controller
{
    public function payables(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['ACCOUNTING', 'KA_SPPG']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) {
            return response()->json(['error'=>['code'=>'no_branch_access','message'=>'No branch access']], 403);
        }

        $rows = PurchaseOrder::query()
            ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
            ->where('b.company_id', $companyId)
            ->whereIn('purchase_orders.branch_id', $allowedBranchIds)
            ->where('purchase_orders.status', 'DELIVERED')
            ->whereIn('purchase_orders.payment_status', ['UNPAID', 'PROOF_UPLOADED', 'SUPPLIER_CONFIRMED'])
            ->orderByDesc('purchase_orders.delivered_at')
            ->limit(200)
            ->select('purchase_orders.*')
            ->get();

        return response()->json(['data' => $rows], 200);
    }

    public function uploadProof(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['ACCOUNTING', 'KA_SPPG']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) {
            return response()->json(['error'=>['code'=>'no_branch_access','message'=>'No branch access']], 403);
        }

        $request->validate([
            'proof' => 'required|file|max:5120|mimes:jpg,jpeg,png,pdf',
            'notes' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($request, $u, $companyId, $allowedBranchIds, $id) {

            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()
                ->where('purchase_orders.id', $id)
                ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
                ->where('b.company_id', $companyId)
                ->select('purchase_orders.*')
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($po->branch_id, $allowedBranchIds, true)) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'No branch access']], 403);
            }

            if ($po->status !== 'DELIVERED') {
                return response()->json(['error' => ['code' => 'po_invalid_state', 'message' => 'Must be DELIVERED']], 409);
            }

            if (!in_array($po->payment_status, ['UNPAID', 'PROOF_UPLOADED'], true)) {
                return response()->json(['error' => ['code' => 'payment_invalid_state', 'message' => 'Cannot upload proof in this state']], 409);
            }

            $file = $request->file('proof');
            $path = $file->store('payment_proofs', 'public');

            if (!empty($po->payment_proof_path) && $po->payment_proof_path !== $path) {
                Storage::disk('public')->delete($po->payment_proof_path);
            }

            $prevPaymentStatus = (string) $po->payment_status;

            $po->payment_proof_path = $path;
            $po->payment_status = 'PROOF_UPLOADED';
            $po->payment_submitted_at = now();
            $po->payment_submitted_by = $u->id;

            if ($request->filled('notes')) {
                $po->notes = trim(($po->notes ? ($po->notes . "\n") : '') . '[PAYMENT] ' . $request->input('notes'));
            }

            $po->save();

            if ($prevPaymentStatus !== (string) $po->payment_status) {
                Audit::log($request, 'payment_proof_uploaded', 'purchase_orders', $po->id, [
                    'from' => $prevPaymentStatus,
                    'to' => (string) $po->payment_status,
                    'branch_id' => $po->branch_id,
                    'payment_proof_path' => $po->payment_proof_path,
                    'notes' => $request->input('notes'),
                    'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
                ]);
            }

            return response()->json($po->fresh(), 200);
        });
    }
}
