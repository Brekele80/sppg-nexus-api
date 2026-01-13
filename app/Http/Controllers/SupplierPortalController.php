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
        $companyId = AuthUser::companyId($request);

        // Company-safe: join through PO.branch -> branches.company_id
        $pos = PurchaseOrder::query()
            ->where('purchase_orders.supplier_id', $u->id)
            ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
            ->where('b.company_id', $companyId)
            ->select('purchase_orders.*')
            ->orderByDesc('purchase_orders.created_at')
            ->limit(50)
            ->get();

        return response()->json($pos, 200);
    }

    // POST /api/supplier/pos/{id}/confirm
    public function confirm(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['SUPPLIER']);
        $companyId = AuthUser::companyId($request);

        $po = PurchaseOrder::query()
            ->where('purchase_orders.id', $id)
            ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
            ->where('b.company_id', $companyId)
            ->select('purchase_orders.*')
            ->firstOrFail();

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
        $companyId = AuthUser::companyId($request);

        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $po = PurchaseOrder::query()
            ->where('purchase_orders.id', $id)
            ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
            ->where('b.company_id', $companyId)
            ->select('purchase_orders.*')
            ->firstOrFail();

        if ($po->supplier_id !== $u->id) abort(403, 'Forbidden (not your PO)');
        if ($po->status !== 'SENT') abort(422, 'PO must be SENT to reject');

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
        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($u, $companyId, $id, $data) {

            $po = PurchaseOrder::query()
                ->where('purchase_orders.id', $id)
                ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
                ->where('b.company_id', $companyId)
                ->select('purchase_orders.*')
                ->lockForUpdate()
                ->firstOrFail();

            if ($po->supplier_id !== $u->id) abort(403, 'Forbidden (not your PO)');
            if ($po->status !== 'CONFIRMED') abort(422, 'PO must be CONFIRMED to mark delivered');

            $prev = $po->status;

            $po->status = 'DELIVERED';
            $po->delivered_at = now();
            $po->save();

            if ($prev !== 'DELIVERED') {
                \App\Jobs\NotifyAccountingPoDelivered::dispatch($po->id)->afterCommit();
            }

            DB::table('purchase_order_events')->insert([
                'id' => (string) Str::uuid(),
                'purchase_order_id' => $po->id,
                'actor_id' => $u->id,
                'event' => 'DELIVERED',
                'message' => $data['note'] ?? 'Delivered',
                'metadata' => json_encode([]),
                'created_at' => now(),
            ]);

            return response()->json($po, 200);
        });
    }
}
