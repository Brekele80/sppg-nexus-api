<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AuthUser;
use App\Support\Audit;

class SupplierPurchaseOrderPaymentController extends Controller
{
    public function confirmPayment(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['SUPPLIER']);

        $companyId = AuthUser::requireCompanyContext($request);

        return DB::transaction(function () use ($request, $id, $u, $companyId) {

            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()
                ->where('purchase_orders.id', $id)
                ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
                ->where('b.company_id', $companyId)
                ->select('purchase_orders.*')
                ->lockForUpdate()
                ->firstOrFail();

            if ($po->supplier_id !== $u->id) {
                return response()->json(['error'=>['code'=>'forbidden','message'=>'Not your PO']], 403);
            }

            if ($po->payment_status !== 'PROOF_UPLOADED') {
                return response()->json(['error' => ['code' => 'payment_invalid_state', 'message' => 'Must be PROOF_UPLOADED']], 409);
            }

            if (empty($po->payment_proof_path)) {
                return response()->json(['error' => ['code' => 'missing_proof', 'message' => 'No payment proof uploaded']], 409);
            }

            $prev = (string) $po->payment_status;

            // Commercial-grade: keep a separate confirm state, then finance can mark PAID if you want.
            // But your current flow sets PAID immediately; we preserve it, and audit it.
            $po->payment_status = 'PAID';
            $po->payment_confirmed_at = now();
            $po->payment_confirmed_by = $u->id;
            $po->paid_at = now();
            $po->save();

            if ($prev !== (string) $po->payment_status) {
                Audit::log($request, 'payment_confirmed', 'purchase_orders', $po->id, [
                    'from' => $prev,
                    'to' => (string) $po->payment_status,
                    'payment_proof_path' => $po->payment_proof_path,
                    'confirmed_at' => (string) $po->payment_confirmed_at,
                    'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
                ]);
            }

            return response()->json($po->fresh(), 200);
        });
    }
}
