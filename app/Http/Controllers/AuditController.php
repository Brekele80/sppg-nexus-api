<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AuthUser;

class AuditController extends Controller
{
    // GET /api/audit?entity=goods_receipts&entity_id=...&limit=200
    public function index(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['ACCOUNTING', 'KA_SPPG', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        $limit = (int) ($request->query('limit', 200));
        if ($limit < 1) $limit = 1;
        if ($limit > 500) $limit = 500;

        $q = DB::table('audit_ledger')
            ->where('company_id', $companyId)
            ->orderByDesc('created_at');

        if ($request->filled('entity')) {
            $q->where('entity', (string) $request->query('entity'));
        }

        if ($request->filled('entity_id')) {
            $q->where('entity_id', (string) $request->query('entity_id'));
        }

        if ($request->filled('actor_id')) {
            $q->where('actor_id', (string) $request->query('actor_id'));
        }

        $rows = $q->limit($limit)->get();

        return response()->json([
            'company_id' => $companyId,
            'filters' => [
                'entity' => $request->query('entity'),
                'entity_id' => $request->query('entity_id'),
                'actor_id' => $request->query('actor_id'),
                'limit' => $limit,
            ],
            'data' => $rows,
        ], 200);
    }
}
