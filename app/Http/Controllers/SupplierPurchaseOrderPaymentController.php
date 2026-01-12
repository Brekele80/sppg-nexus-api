<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierPurchaseOrderPaymentController extends Controller
{
    /**
     * Supplier confirms payment and mark as SUPPLIER_CONFIRMED (or PAID).
     * POST /api/supplier/purchase-orders/{id}/confirm-payment
     */
    public function confirmPayment(Request $request, string $id)
    {
        $user = $request->attributes->get('auth_user');

        return DB::transaction(function () use ($id, $user) {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::lockForUpdate()->findOrFail($id);

            // Ensure proof exists
            if ($po->payment_status !== 'PROOF_UPLOADED') {
                return response()->json(['error' => ['code' => 'payment_invalid_state', 'message' => 'Must be PROOF_UPLOADED']], 409);
            }

            if (empty($po->payment_proof_path)) {
                return response()->json(['error' => ['code' => 'missing_proof', 'message' => 'No payment proof uploaded']], 409);
            }

            // Optional: if you want strict supplier ownership:
            // if ($po->supplier_id !== $user->supplier_id) { ... } // depends on your profile mapping

            $po->payment_status = 'SUPPLIER_CONFIRMED';
            $po->payment_confirmed_at = now();
            $po->payment_confirmed_by = $user->id;

            // Minimal: you can choose to mark paid immediately after confirmation:
            $po->paid_at = now();
            $po->payment_status = 'PAID';

            $po->save();

            return response()->json($po->fresh(), 200);
        });
    }
}
