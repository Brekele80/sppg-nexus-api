<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AuthUser;
use App\Support\AccessScope;

class DashboardController extends Controller
{
    // GET /api/dashboard/company
    public function company(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireCompany($u);

        $branchIds = AccessScope::branchIdsForUser($u);
        if (!$branchIds) {
            return response()->json([
                'branches' => 0,
                'kpis' => [],
                'recent' => [],
            ]);
        }

        $kpis = [
            'branches' => count($branchIds),

            'po_delivered' => DB::table('purchase_orders')->whereIn('branch_id', $branchIds)->where('status', 'DELIVERED')->count(),
            'po_sent'      => DB::table('purchase_orders')->whereIn('branch_id', $branchIds)->where('status', 'SENT')->count(),
            'po_draft'     => DB::table('purchase_orders')->whereIn('branch_id', $branchIds)->where('status', 'DRAFT')->count(),

            // payment status (from your new payment_flow columns)
            'po_payables'  => DB::table('purchase_orders')->whereIn('branch_id', $branchIds)
                ->whereIn('payment_status', ['PENDING', 'PROOF_UPLOADED'])
                ->count(),

            'inventory_skus' => DB::table('inventory_items')->whereIn('branch_id', $branchIds)->count(),
        ];

        $recentPo = DB::table('purchase_orders')
            ->select('id', 'po_number', 'status', 'branch_id', 'total', 'currency', 'updated_at')
            ->whereIn('branch_id', $branchIds)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        return response()->json([
            'branches' => count($branchIds),
            'kpis' => $kpis,
            'recent_purchase_orders' => $recentPo,
        ]);
    }

    // GET /api/dashboard/branch/{branchId}
    public function branch(Request $request, string $branchId)
    {
        $u = AuthUser::get($request);
        AuthUser::requireCompany($u);

        AccessScope::assertBranchAccess($u, $branchId);

        $kpis = [
            'po_delivered' => DB::table('purchase_orders')->where('branch_id', $branchId)->where('status', 'DELIVERED')->count(),
            'po_sent'      => DB::table('purchase_orders')->where('branch_id', $branchId)->where('status', 'SENT')->count(),
            'po_draft'     => DB::table('purchase_orders')->where('branch_id', $branchId)->where('status', 'DRAFT')->count(),
            'po_payables'  => DB::table('purchase_orders')->where('branch_id', $branchId)
                ->whereIn('payment_status', ['PENDING', 'PROOF_UPLOADED'])
                ->count(),

            'inventory_skus' => DB::table('inventory_items')->where('branch_id', $branchId)->count(),
        ];

        $recentMovements = DB::table('inventory_movements')
            ->where('branch_id', $branchId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'branch_id' => $branchId,
            'kpis' => $kpis,
            'recent_inventory_movements' => $recentMovements,
        ]);
    }
}
