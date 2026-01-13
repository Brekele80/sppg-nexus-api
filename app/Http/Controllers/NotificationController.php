<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AuthUser;
use App\Models\Profile;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $u = AuthUser::get($request);
        $companyId = AuthUser::requireCompanyContext($request);

        // Company-safe: profile must be in company
        if ((string)$u->company_id !== (string)$companyId) {
            return response()->json([
                'error' => ['code' => 'company_forbidden', 'message' => 'Company mismatch']
            ], 403);
        }

        $rows = DB::table('notifications')
            ->where('notifiable_type', Profile::class)
            ->where('notifiable_id', $u->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function ($n) {
                // data can be json/jsonb; DB driver may return string
                $data = $n->data;
                if (is_string($data)) {
                    $decoded = json_decode($data, true);
                    $data = json_last_error() === JSON_ERROR_NONE ? $decoded : ['raw' => $n->data];
                }

                return [
                    'id' => $n->id,
                    'type' => $n->type,
                    'data' => $data,
                    'read_at' => $n->read_at,
                    'created_at' => $n->created_at,
                ];
            });

        return response()->json([
            'value' => $rows,
            'count' => $rows->count(),
        ], 200);
    }

    public function markRead(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        $companyId = AuthUser::requireCompanyContext($request);

        if ((string)$u->company_id !== (string)$companyId) {
            return response()->json([
                'error' => ['code' => 'company_forbidden', 'message' => 'Company mismatch']
            ], 403);
        }

        $now = now();

        $updated = DB::table('notifications')
            ->where('id', $id)
            ->where('notifiable_type', Profile::class)
            ->where('notifiable_id', $u->id)
            ->update(['read_at' => $now, 'updated_at' => $now]);

        if (!$updated) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Notification not found']
            ], 404);
        }

        $row = DB::table('notifications')->where('id', $id)->first();
        $data = $row->data;
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = json_last_error() === JSON_ERROR_NONE ? $decoded : ['raw' => $row->data];
        }

        return response()->json([
            'id' => $row->id,
            'type' => $row->type,
            'data' => $data,
            'read_at' => $row->read_at,
            'created_at' => $row->created_at,
        ], 200);
    }

    public function markAllRead(Request $request)
    {
        $u = AuthUser::get($request);
        $companyId = AuthUser::requireCompanyContext($request);

        if ((string)$u->company_id !== (string)$companyId) {
            return response()->json([
                'error' => ['code' => 'company_forbidden', 'message' => 'Company mismatch']
            ], 403);
        }

        $now = now();

        DB::table('notifications')
            ->where('notifiable_type', Profile::class)
            ->where('notifiable_id', $u->id)
            ->whereNull('read_at')
            ->update(['read_at' => $now, 'updated_at' => $now]);

        return response()->json(['ok' => true], 200);
    }
}
