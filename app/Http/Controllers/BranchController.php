<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Support\AuthUser;

class BranchController extends Controller
{
    /**
     * GET /api/dc/branches
     * Query:
     * - q: optional string (search by code/name/city/province/phone)
     * - limit: optional int (1..200)
     *
     * Security:
     * - Company scoped (requireCompany middleware)
     * - Allowed branches enforced (defense-in-depth)
     */
    public function index(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        $allowed = AuthUser::allowedBranchIds($request);
        if (empty($allowed)) {
            return response()->json([
                'error' => ['code' => 'no_branch_access', 'message' => 'No branch access for this company']
            ], 403);
        }

        $data = $request->validate([
            'q'     => 'nullable|string|max:200',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $q = isset($data['q']) ? trim((string)$data['q']) : '';
        $limit = (int)($data['limit'] ?? 50);

        $query = DB::table('branches')
            ->where('company_id', (string)$companyId)
            ->whereIn('id', array_values($allowed));

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'ilike', "%{$q}%")
                  ->orWhere('code', 'ilike', "%{$q}%")
                  ->orWhere('city', 'ilike', "%{$q}%")
                  ->orWhere('province', 'ilike', "%{$q}%")
                  ->orWhere('phone', 'ilike', "%{$q}%");
            });
        }

        $rows = $query
            ->orderBy('name')
            ->limit($limit)
            ->get([
                'id',
                'company_id',
                'code',
                'name',
                'city',
                'province',
                'address',
                'phone',
                'created_at',
                'updated_at',
            ]);

        return response()->json([
            'company_id' => (string)$companyId,
            'filters' => [
                'q'     => $q ?: null,
                'limit' => $limit,
            ],
            'data' => $rows,
        ], 200);
    }
}
