<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AuthUser;

class InventoryController extends Controller
{
    // GET /api/inventory
    public function index(Request $request)
    {
        $u = AuthUser::get($request);
        if (!$u) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated']], 401);
        }

        // Branch-scoped inventory
        $branchId = $u->branch_id;

        $rows = DB::table('inventory_ledgers')
            ->selectRaw("
                item_name,
                SUM(CASE WHEN direction = 'IN'  THEN qty ELSE 0 END) -
                SUM(CASE WHEN direction = 'OUT' THEN qty ELSE 0 END) AS on_hand
            ")
            ->where('branch_id', $branchId)
            ->groupBy('item_name')
            ->orderBy('item_name')
            ->get();

        return response()->json($rows, 200);
    }

    // GET /api/inventory/movements (optional quick view)
    public function movements(Request $request)
    {
        $u = AuthUser::get($request);
        if (!$u) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated']], 401);
        }

        $rows = DB::table('inventory_ledgers')
            ->where('branch_id', $u->branch_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json($rows, 200);
    }
}
