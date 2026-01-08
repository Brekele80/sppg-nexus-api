<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\AuthUser;

class SupplierPortalController extends Controller
{
    // GET /api/supplier/pos
    public function myPurchaseOrders(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['SUPPLIER']);

        $pos = PurchaseOrder::where('supplier_id', $u->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($pos, 200);
    }

    // POST /api/supplier/pos/{id}/confirm
    public function confirm(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['SUPPLIER']);

        $po = PurchaseOrder::where('id', $id)->firstOrFail();
        if ($po->supplier_id !== $u->id) abort(403, 'Forbidden (not your PO)');

        if ($po->status !== 'SENT') abort(422, 'PO must be SENT to confirm');

        $po->status = 'CONFIRMED';
        $po->confirmed_at = now();
        $po->save();

        DB::table('purchase_order_events')->insert([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $po->id,
            'actor_id' => $u->id,
            'event' => 'CONFIRMED',
            'message' => 'Supplier confirmed PO',
            'metadata' => json_encode([]),
            'created_at' => now(),
        ]);

        return response()->json($po, 200);
    }

    // POST /api/supplier/pos/{id}/reject
    public function reject(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['SUPPLIER']);

        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $po = PurchaseOrder::where('id', $id)->firstOrFail();
        if ($po->supplier_id !== $u->id) abort(403, 'Forbidden (not your PO)');

        if (!in_array($po->status, ['SENT'], true)) abort(422, 'PO must be SENT to reject');

        $po->status = 'REJECTED';
        $po->save();

        DB::table('purchase_order_events')->insert([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $po->id,
            'actor_id' => $u->id,
            'event' => 'REJECTED',
            'message' => $data['reason'],
            'metadata' => json_encode([]),
            'created_at' => now(),
        ]);

        return response()->json($po, 200);
    }

    // POST /api/supplier/pos/{id}/delivered
    public function markDelivered(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['SUPPLIER']);

        $data = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($u, $id, $data) {

            // lock PO to prevent double-posting
            $po = PurchaseOrder::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($po->supplier_id !== $u->id) abort(403, 'Forbidden (not your PO)');
            if ($po->status !== 'CONFIRMED') abort(422, 'PO must be CONFIRMED to mark delivered');

            // 1. Mark PO delivered
            $po->status = 'DELIVERED';
            $po->delivered_at = now();
            $po->save();

            // 2. Event log
            DB::table('purchase_order_events')->insert([
                'id' => (string) Str::uuid(),
                'purchase_order_id' => $po->id,
                'actor_id' => $u->id,
                'event' => 'DELIVERED',
                'message' => $data['note'] ?? 'Delivered',
                'metadata' => json_encode([]),
                'created_at' => now(),
            ]);

            // 3. Inventory ledger (THIS is the new logic)
            $items = DB::table('purchase_order_items')
                ->where('purchase_order_id', $po->id)
                ->get();

            foreach ($items as $item) {
                DB::table('inventory_ledgers')->insert([
                    'branch_id' => $po->branch_id,
                    'item_name' => $item->item_name,
                    'ref_type'  => 'PO',
                    'ref_id'    => $po->id,
                    'qty'       => $item->qty,
                    'direction' => 'IN',
                    'created_at'=> now(),
                ]);
            }

            return response()->json($po, 200);
        });
    }
}
