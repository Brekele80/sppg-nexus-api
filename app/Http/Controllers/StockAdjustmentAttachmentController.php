<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockAdjustmentAttachmentController extends Controller
{
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
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Stock adjustment not found']
            ], 404);
        }

        if ((string) $doc->company_id !== (string) $companyId) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Forbidden']
            ], 403);
        }

        if (!in_array((string) $doc->branch_id, $allowed, true)) {
            return response()->json([
                'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']
            ], 403);
        }

        $rows = DB::table('stock_adjustment_attachments')
            ->where('stock_adjustment_id', $id)
            ->where('company_id', (string) $companyId)
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
        ], 200);
    }

    /**
     * POST /api/dc/stock-adjustments/{id}/attachments
     * Registers attachment metadata (file already uploaded to storage).
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

        $maxBytes = (int) env('ATTACHMENTS_MAX_BYTES', 10 * 1024 * 1024);
        $allowedMime = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ATTACHMENTS_ALLOWED_MIME', 'application/pdf'))
        )));

        $data = $request->validate([
            'file_name'   => 'required|string|max:255',
            'mime_type'   => 'nullable|string|max:120',
            'file_size'   => 'nullable|integer|min:1|max:' . $maxBytes,
            'storage_key' => 'required|string|max:1024',
            'public_url'  => 'nullable|string|max:2048',
        ]);

        $fileName = trim((string) $data['file_name']);
        if ($fileName === '') {
            return response()->json([
                'error' => ['code' => 'invalid_file_name', 'message' => 'file_name must not be empty']
            ], 422);
        }

        if (!empty($data['mime_type']) && !in_array($data['mime_type'], $allowedMime, true)) {
            return response()->json([
                'error' => [
                    'code' => 'mime_not_allowed',
                    'message' => 'mime_type not allowed',
                    'details' => ['allowed' => $allowedMime, 'got' => $data['mime_type']],
                ]
            ], 422);
        }

        return DB::transaction(function () use ($request, $id, $companyId, $u, $allowed, $data, $fileName) {
            $doc = DB::table('stock_adjustments')->where('id', $id)->lockForUpdate()->first();

            if (!$doc) {
                return response()->json([
                    'error' => ['code' => 'not_found', 'message' => 'Stock adjustment not found']
                ], 404);
            }

            if ((string) $doc->company_id !== (string) $companyId) {
                return response()->json([
                    'error' => ['code' => 'forbidden', 'message' => 'Forbidden']
                ], 403);
            }

            if (!in_array((string) $doc->branch_id, $allowed, true)) {
                return response()->json([
                    'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']
                ], 403);
            }

            $status = (string) $doc->status;
            if (!in_array($status, ['DRAFT', 'SUBMITTED'], true)) {
                return response()->json([
                    'error' => [
                        'code' => 'invalid_status',
                        'message' => 'Attachments can only be modified in DRAFT or SUBMITTED',
                        'details' => ['status' => $status],
                    ]
                ], 409);
            }

            $storageKey = $this->normalizeStorageKey((string) $data['storage_key']);
            $this->enforceStorageKeyPrefix($storageKey, (string) $companyId, (string) $doc->id);

            $attachmentId = (string) Str::uuid();

            DB::table('stock_adjustment_attachments')->insert([
                'id'                  => $attachmentId,
                'stock_adjustment_id' => (string) $doc->id,
                'company_id'          => (string) $companyId,
                'uploaded_by'         => (string) $u->id,
                'file_name'           => $fileName,
                'mime_type'           => $data['mime_type'] ?? null,
                'file_size'           => $data['file_size'] ?? null,
                'storage_key'         => $storageKey,
                'public_url'          => $data['public_url'] ?? null,
                'created_at'          => now(),
            ]);

            Audit::log($request, 'attach', 'stock_adjustments', (string) $doc->id, [
                'attachment_id'   => $attachmentId,
                'file_name'       => $fileName,
                'storage_key'     => $storageKey,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            $row = DB::table('stock_adjustment_attachments')
                ->where('id', $attachmentId)
                ->where('company_id', (string) $companyId)
                ->first();

            return response()->json([
                'id'                  => (string) $row->id,
                'stock_adjustment_id' => (string) $row->stock_adjustment_id,
                'company_id'          => (string) $row->company_id,
                'uploaded_by'         => (string) $row->uploaded_by,
                'file_name'           => (string) $row->file_name,
                'mime_type'           => $row->mime_type,
                'file_size'           => $row->file_size,
                'storage_key'         => (string) $row->storage_key,
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

        return DB::transaction(function () use ($request, $id, $attId, $companyId, $allowed) {
            $doc = DB::table('stock_adjustments')->where('id', $id)->lockForUpdate()->first();

            if (!$doc) {
                return response()->json([
                    'error' => ['code' => 'not_found', 'message' => 'Stock adjustment not found']
                ], 404);
            }

            if ((string) $doc->company_id !== (string) $companyId) {
                return response()->json([
                    'error' => ['code' => 'forbidden', 'message' => 'Forbidden']
                ], 403);
            }

            if (!in_array((string) $doc->branch_id, $allowed, true)) {
                return response()->json([
                    'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']
                ], 403);
            }

            $status = (string) $doc->status;
            if (!in_array($status, ['DRAFT', 'SUBMITTED'], true)) {
                return response()->json([
                    'error' => [
                        'code' => 'invalid_status',
                        'message' => 'Attachments can only be modified in DRAFT or SUBMITTED',
                        'details' => ['status' => $status],
                    ]
                ], 409);
            }

            $att = DB::table('stock_adjustment_attachments')
                ->where('id', $attId)
                ->where('stock_adjustment_id', $id)
                ->where('company_id', (string) $companyId)
                ->first();

            if (!$att) {
                return response()->json([
                    'error' => ['code' => 'not_found', 'message' => 'Attachment not found']
                ], 404);
            }

            DB::table('stock_adjustment_attachments')
                ->where('id', $attId)
                ->where('company_id', (string) $companyId)
                ->delete();

            Audit::log($request, 'detach', 'stock_adjustments', (string) $doc->id, [
                'attachment_id'   => (string) $attId,
                'file_name'       => (string) $att->file_name,
                'storage_key'     => (string) $att->storage_key,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json(['ok' => true], 200);
        });
    }

    /**
     * GET /api/dc/stock-adjustments/{id}/attachments/{attId}/download
     * Returns a short-lived signed URL for private Supabase Storage.
     */
    public function download(Request $request, string $id, string $attId)
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
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Stock adjustment not found']
            ], 404);
        }

        if ((string) $doc->company_id !== (string) $companyId) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Forbidden']
            ], 403);
        }

        if (!in_array((string) $doc->branch_id, $allowed, true)) {
            return response()->json([
                'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']
            ], 403);
        }

        $att = DB::table('stock_adjustment_attachments')
            ->where('id', $attId)
            ->where('stock_adjustment_id', $id)
            ->where('company_id', (string) $companyId)
            ->first();

        if (!$att) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Attachment not found']
            ], 404);
        }

        $bucket = (string) env('SUPABASE_STORAGE_PRIVATE_BUCKET', 'private');
        $supabaseUrl = rtrim((string) env('SUPABASE_URL', ''), '/');
        $serviceKey = (string) env('SUPABASE_SERVICE_ROLE_KEY', '');

        if ($supabaseUrl === '' || $serviceKey === '') {
            return response()->json([
                'error' => [
                    'code' => 'server_misconfig',
                    'message' => 'SUPABASE_URL or SUPABASE_SERVICE_ROLE_KEY missing'
                ]
            ], 500);
        }

        $storageKey = $this->normalizeStorageKey((string) $att->storage_key);
        $this->enforceStorageKeyPrefix($storageKey, (string) $companyId, (string) $doc->id);

        // Supabase sign endpoint:
        // POST {SUPABASE_URL}/storage/v1/object/sign/{bucket}/{path}
        $expiresIn = 60;
        $path = $storageKey;

        $signUrl = $supabaseUrl
            . '/storage/v1/object/sign/'
            . rawurlencode($bucket)
            . '/'
            . str_replace('%2F', '/', rawurlencode($path));

        $ch = curl_init($signUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['expiresIn' => $expiresIn]));

        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $http < 200 || $http >= 300) {
            return response()->json([
                'error' => [
                    'code' => 'supabase_sign_failed',
                    'message' => 'Failed to create signed URL',
                    'details' => ['http' => $http, 'curl_error' => $err, 'body' => $raw],
                ]
            ], 502);
        }

        $json = json_decode($raw, true);
        $signed = $json['signedURL'] ?? $json['signedUrl'] ?? null;

        if (!$signed || !is_string($signed)) {
            return response()->json([
                'error' => [
                    'code' => 'supabase_sign_invalid',
                    'message' => 'Supabase sign response missing signedURL',
                    'details' => ['body' => $json],
                ]
            ], 502);
        }

        // Normalize: absolute + ensure exactly ONE /storage/v1
        $signedUrl = $this->normalizeSupabaseSignedUrl($supabaseUrl, $signed);

        Audit::log($request, 'download_attachment', 'stock_adjustments', (string) $doc->id, [
            'attachment_id' => (string) $attId,
            'storage_key'   => $storageKey,
        ]);

        return response()->json([
            'attachment_id' => (string) $attId,
            'file_name'     => (string) $att->file_name,
            'expires_in'    => $expiresIn,
            'url'           => $signedUrl,
        ], 200);
    }

    /**
     * companies/{companyId}/stock-adjustments/{docId}/...
     */
    private function requiredPrefix(string $companyId, string $docId): string
    {
        return "companies/{$companyId}/stock-adjustments/{$docId}/";
    }

    private function normalizeStorageKey(string $storageKey): string
    {
        $k = trim($storageKey);
        $k = ltrim($k, '/');
        $k = str_replace('\\', '/', $k);

        while (str_contains($k, '//')) {
            $k = str_replace('//', '/', $k);
        }

        if ($k === '' || str_contains($k, '../') || str_contains($k, '..\\') || str_contains($k, "\0")) {
            abort(422, 'Invalid storage_key');
        }

        return $k;
    }

    private function enforceStorageKeyPrefix(string $storageKey, string $companyId, string $docId): void
    {
        $prefix = $this->requiredPrefix($companyId, $docId);

        if (!str_starts_with($storageKey, $prefix)) {
            abort(422, 'storage_key must be within doc namespace');
        }

        // Require at least: "{attFolder}/{filename.ext}"
        $rest = substr($storageKey, strlen($prefix));
        if ($rest === '' || str_ends_with($storageKey, '/') || !str_contains($rest, '/')) {
            abort(422, 'storage_key must include attachment folder and filename');
        }
    }

    /**
     * Fixes:
     * - relative -> absolute
     * - /object/sign -> /storage/v1/object/sign
     * - double /storage/v1/storage/v1 -> single
     */
    private function normalizeSupabaseSignedUrl(string $supabaseUrl, string $signed): string
    {
        $supabaseUrl = rtrim($supabaseUrl, '/');
        $signed = trim($signed);

        // If relative path, make absolute
        if (str_starts_with($signed, '/')) {
            $signed = $supabaseUrl . $signed;
        }

        // Fix missing /storage/v1
        $signed = str_replace($supabaseUrl . '/object/sign/', $supabaseUrl . '/storage/v1/object/sign/', $signed);
        $signed = str_replace('/object/sign/', '/storage/v1/object/sign/', $signed);

        // Remove accidental double prefix
        $signed = str_replace($supabaseUrl . '/storage/v1/storage/v1/', $supabaseUrl . '/storage/v1/', $signed);
        $signed = str_replace('/storage/v1/storage/v1/', '/storage/v1/', $signed);

        return $signed;
    }
}
