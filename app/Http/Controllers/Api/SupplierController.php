<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        // Match the rest of your API controllers
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated']], 401);
        }

        // If you want this visible to all authenticated roles (CHEF, ACCOUNTING, etc.)
        // then no role check here. Otherwise implement role-based restriction.

        $suppliers = DB::table('suppliers')
            ->select('id', 'code', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($suppliers);
    }
}
