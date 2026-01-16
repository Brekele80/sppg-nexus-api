<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use App\Support\Audit;
use App\Support\AuthUser;

class StockAdjustmentAttachmentController extends Controller
{
    /**
     * Storage key must be tenant-safe and doc-scoped:
     * companies/{companyId}/stock-adjustments/{docId}/...
     */
    private function expectedPrefix(string $companyId, string $docId): string
    {
        return "companies/{$companyId}/stock-adjustments/{$docId}/";
    }

    private function normalizeKey(string $key): string
    {
        $k = trim($key);
        $k = ltrim($k, '/');
        // normalize backslashes just in case client sends Windows paths
        $k = str_replace('\\', '/', $k);
        // collapse double slashes
        while (str_contains($k, '//')) {
            $k = str_replace('//', '/', $k);
        }
        return $k;
    }

    /**
     * GET /api/dc/stock-adjustments/{id}/attachments
     */
    public function index(Request $request, string $id)
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

        $doc = DB::table('stock_adjustments')->where('id', $id)->first();
        if (!$doc) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Stock adjustment not found']], 404);
        }
        if ((string)$doc->company_id !== (string)$companyId) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
        }
        if (!in_array((string)$doc->branch_id, $allowed, true)) {
            return response()->json(['error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']], 403);
        }

        // defense-in-depth: scope attachments to company_id too
        $rows = DB::table('stock_adjustment_attachments')
            ->where('stock_adjustment_id', $id)
            ->where('company_id', (string)$companyId)
            ->orderByDesc('created_at')
            ->get([
                'id',
                'stock_adjustment_id',
                'company_id',
                'uploaded_by',
                'file_name',
                'mime_type',
                'file_size',
                'storage_key',
                'public_url',
                'created_at',
            ]);

        return response()->json([
            'doc_id' => $id,
            'data'   => $rows,
        ]);
    }

    /**
     * POST /api/dc/stock-adjustments/{id}/attachments
     * Register attachment metadata (file already uploaded to storage).
     */
    public function store(Request $request, string $id)
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
            'file_name'   => 'required|string|max:255',
            'mime_type'   => 'nullable|string|max:120',
            'file_size'   => 'nullable|integer|min:1',
            'storage_key' => 'required|string|max:1024',
            'public_url'  => 'nullable|string|max:2048',
        ]);

        return DB::transaction(function () use ($request, $id, $companyId, $u, $allowed, $data) {
            $doc = DB::table('stock_adjustments')->where('id', $id)->lockForUpdate()->first();
            if (!$doc) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Stock adjustment not found']], 404);
            }
            if ((string)$doc->company_id !== (string)$companyId) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
            }
            if (!in_array((string)$doc->branch_id, $allowed, true)) {
                return response()->json(['error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']], 403);
            }

            $status = (string)$doc->status;
            if (!in_array($status, ['DRAFT', 'SUBMITTED'], true)) {
                return response()->json([
                    'error' => [
                        'code' => 'invalid_status',
                        'message' => 'Attachments can only be modified in DRAFT or SUBMITTED',
                        'details' => ['status' => $status],
                    ]
                ], 409);
            }

            $attachmentId = (string) Str::uuid();

            $storageKey = $this->normalizeKey((string)$data['storage_key']);
            if ($storageKey === '') {
                return response()->json(['error' => ['code' => 'invalid_storage_key', 'message' => 'storage_key must not be empty']], 422);
            }

            // enforce tenant-safe + doc-scoped convention
            $prefix = $this->expectedPrefix((string)$companyId, (string)$doc->id);
            if (!str_starts_with($storageKey, $prefix)) {
                return response()->json([
                    'error' => [
                        'code' => 'invalid_storage_key_prefix',
                        'message' => 'storage_key must be under the document prefix',
                        'details' => [
                            'expected_prefix' => $prefix,
                            'got' => $storageKey,
                        ],
                    ]
                ], 422);
            }

            $fileName = trim((string)$data['file_name']);
            if ($fileName === '') {
                return response()->json(['error' => ['code' => 'invalid_file_name', 'message' => 'file_name must not be empty']], 422);
            }

            DB::table('stock_adjustment_attachments')->insert([
                'id'                  => $attachmentId,
                'stock_adjustment_id' => (string)$doc->id,
                'company_id'          => (string)$companyId,
                'uploaded_by'         => (string)$u->id,
                'file_name'           => $fileName,
                'mime_type'           => $data['mime_type'] ?? null,
                'file_size'           => $data['file_size'] ?? null,
                'storage_key'         => $storageKey,
                'public_url'          => $data['public_url'] ?? null,
                'created_at'          => now(),
            ]);

            $row = DB::table('stock_adjustment_attachments')
                ->where('id', $attachmentId)
                ->where('company_id', (string)$companyId)
                ->first();

            Audit::log($request, 'attach', 'stock_adjustments', (string)$doc->id, [
                'attachment_id'   => $attachmentId,
                'file_name'       => $fileName,
                'storage_key'     => $storageKey,
                'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
            ]);

            return response()->json([
                'id'                  => (string)$row->id,
                'stock_adjustment_id' => (string)$row->stock_adjustment_id,
                'company_id'          => (string)$row->company_id,
                'uploaded_by'         => (string)$row->uploaded_by,
                'file_name'           => (string)$row->file_name,
                'mime_type'           => $row->mime_type,
                'file_size'           => $row->file_size,
                'storage_key'         => (string)$row->storage_key,
                'public_url'          => $row->public_url,
                'created_at'          => $row->created_at,
            ], 200);
        });
    }

    /**
     * DELETE /api/dc/stock-adjustments/{id}/attachments/{attId}
     * Removes metadata only (does not delete blob).
     */
    public function destroy(Request $request, string $id, string $attId)
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

        return DB::transaction(function () use ($request, $id, $attId, $companyId, $u, $allowed) {
            $doc = DB::table('stock_adjustments')->where('id', $id)->lockForUpdate()->first();
            if (!$doc) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Stock adjustment not found']], 404);
            }
            if ((string)$doc->company_id !== (string)$companyId) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
            }
            if (!in_array((string)$doc->branch_id, $allowed, true)) {
                return response()->json(['error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']], 403);
            }

            $status = (string)$doc->status;
            if (!in_array($status, ['DRAFT', 'SUBMITTED'], true)) {
                return response()->json([
                    'error' => [
                        'code' => 'invalid_status',
                        'message' => 'Attachments can only be modified in DRAFT or SUBMITTED',
                        'details' => ['status' => $status],
                    ]
                ], 409);
            }

            // defense-in-depth: ensure attachment belongs to same company + doc
            $att = DB::table('stock_adjustment_attachments')
                ->where('id', $attId)
                ->where('stock_adjustment_id', $id)
                ->where('company_id', (string)$companyId)
                ->first();

            if (!$att) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Attachment not found']], 404);
            }

            DB::table('stock_adjustment_attachments')
                ->where('id', $attId)
                ->where('company_id', (string)$companyId)
                ->delete();

            Audit::log($request, 'detach', 'stock_adjustments', (string)$doc->id, [
                'attachment_id'   => (string)$attId,
                'file_name'       => (string)$att->file_name,
                'storage_key'     => (string)$att->storage_key,
                'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
            ]);

            return response()->json(['ok' => true], 200);
        });
    }
}
