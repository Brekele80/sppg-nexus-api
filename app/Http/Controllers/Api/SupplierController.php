<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AuthUser;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $u = AuthUser::get($request);
        $companyId = AuthUser::companyId($request);

        // Suppliers = profiles that have role SUPPLIER within this company
        $suppliers = DB::table('profiles as p')
            ->join('user_roles as ur', 'ur.user_id', '=', 'p.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('p.company_id', $companyId)
            ->where('r.code', 'SUPPLIER')
            ->select('p.id', 'p.email', 'p.full_name')
            ->orderBy('p.full_name')
            ->get();

        return response()->json($suppliers, 200);
    }
}
