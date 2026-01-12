<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AccountingPurchaseOrderPaymentController extends Controller
{
    /**
     * List delivered POs that are not paid yet (minimal "notification").
     * GET /api/accounting/purchase-orders/payables
     */
    public function payables(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        $rows = PurchaseOrder::query()
            ->where('branch_id', $user->branch_id)
            ->where('status', 'DELIVERED')
            ->whereIn('payment_status', ['UNPAID', 'PROOF_UPLOADED', 'SUPPLIER_CONFIRMED'])
            ->orderByDesc('delivered_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * Upload proof and mark as PROOF_UPLOADED.
     * POST /api/accounting/purchase-orders/{id}/payment-proof
     * multipart/form-data: proof (file), notes? (string)
     */
    public function uploadProof(Request $request, string $id)
    {
        $user = $request->attributes->get('auth_user');

        $request->validate([
            'proof' => 'required|file|max:5120|mimes:jpg,jpeg,png,pdf', // 5MB
            'notes' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($request, $user, $id) {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::lockForUpdate()->findOrFail($id);

            // Safety: enforce branch ownership for accounting user
            if ($po->branch_id !== $user->branch_id) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Wrong branch']], 403);
            }

            // Recommended minimal rule: can upload proof only after delivered
            if ($po->status !== 'DELIVERED') {
                return response()->json(['error' => ['code' => 'po_invalid_state', 'message' => 'Must be DELIVERED']], 409);
            }

            if (!in_array($po->payment_status, ['UNPAID', 'PROOF_UPLOADED'], true)) {
                return response()->json(['error' => ['code' => 'payment_invalid_state', 'message' => 'Cannot upload proof in this state']], 409);
            }

            $file = $request->file('proof');

            // Store on "public" disk (local). You can swap to S3/Supabase later.
            $path = $file->store('payment_proofs', 'public');

            // If replacing an existing proof, delete old file
            if (!empty($po->payment_proof_path) && $po->payment_proof_path !== $path) {
                Storage::disk('public')->delete($po->payment_proof_path);
            }

            $po->payment_proof_path = $path;
            $po->payment_status = 'PROOF_UPLOADED';
            $po->payment_submitted_at = now();
            $po->payment_submitted_by = $user->id;

            // Optional: append to PO notes (minimal)
            if ($request->filled('notes')) {
                $po->notes = trim(($po->notes ? ($po->notes . "\n") : '') . '[PAYMENT] ' . $request->input('notes'));
            }

            $po->save();

            return response()->json($po->fresh(), 200);
        });
    }
}
