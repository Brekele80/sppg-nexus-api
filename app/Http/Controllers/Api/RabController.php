<?php

namespace App\Http\Controllers\Api;

use App\Models\RabLineItem;
use App\Models\RabVersion;
use App\Models\PurchaseRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\AuthUser;
use App\Support\Audit;

class RabController extends BaseApiController
{
    public function createForPr(Request $request, string $prId)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['PURCHASE_CABANG']);

        $companyId = AuthUser::companyId($request);
        $allowed = AuthUser::allowedBranchIds($request);

        $pr = PurchaseRequest::query()
            ->where('purchase_requests.id', $prId)
            ->join('branches as b', 'b.id', '=', 'purchase_requests.branch_id')
            ->where('b.company_id', $companyId)
            ->select('purchase_requests.*')
            ->firstOrFail();

        if (!in_array($pr->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

        $data = $request->validate([
            'currency' => 'nullable|string|max:10',
            'line_items' => 'required|array|min:1',
            'line_items.*.item_name' => 'required|string|max:255',
            'line_items.*.unit' => 'required|string|max:50',
            'line_items.*.qty' => 'required|numeric|min:0.001',
            'line_items.*.unit_price' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $u, $pr, $data) {
            $maxVer = RabVersion::where('purchase_request_id', $pr->id)->max('version_no') ?? 0;
            $verNo = $maxVer + 1;
            $rabId = (string) Str::uuid();

            $rab = RabVersion::create([
                'id' => $rabId,
                'purchase_request_id' => $pr->id,
                'version_no' => $verNo,
                'created_by' => $u->id,
                'status' => 'DRAFT',
                'currency' => $data['currency'] ?? 'IDR',
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
            ]);

            $this->replaceLineItems($rab, $data['line_items']);

            Audit::log($request, 'create', 'rab_versions', $rab->id, [
                'purchase_request_id' => $pr->id,
                'branch_id' => $pr->branch_id,
                'version_no' => $verNo,
                'currency' => $rab->currency,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadRab($rabId), 201);
        });
    }

    public function show(Request $request, string $id)
    {
        $this->authUser($request);
        $companyId = AuthUser::companyId($request);

        $rab = $this->loadRabGuarded($companyId, $id, $request);
        return response()->json($rab);
    }

    public function updateDraft(Request $request, string $id)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['PURCHASE_CABANG']);
        $companyId = AuthUser::companyId($request);

        $rab = $this->loadRabGuarded($companyId, $id, $request);
        $rab->load('lineItems');

        if ($rab->status !== 'DRAFT') {
            return response()->json(['message' => 'Only DRAFT RAB can be edited'], 422);
        }

        $data = $request->validate([
            'currency' => 'nullable|string|max:10',
            'line_items' => 'required|array|min:1',
            'line_items.*.item_name' => 'required|string|max:255',
            'line_items.*.unit' => 'required|string|max:50',
            'line_items.*.qty' => 'required|numeric|min:0.001',
            'line_items.*.unit_price' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $rab, $data) {
            $before = [
                'currency' => $rab->currency,
                'subtotal' => (float) $rab->subtotal,
                'tax' => (float) $rab->tax,
                'total' => (float) $rab->total,
                'line_items_count' => $rab->lineItems->count(),
            ];

            if (isset($data['currency'])) {
                $rab->currency = $data['currency'];
                $rab->save();
            }

            $this->replaceLineItems($rab, $data['line_items']);

            $after = [
                'currency' => $rab->currency,
                'subtotal' => (float) $rab->subtotal,
                'tax' => (float) $rab->tax,
                'total' => (float) $rab->total,
                'line_items_count' => count($data['line_items']),
            ];

            Audit::log($request, 'update', 'rab_versions', $rab->id, [
                'before' => $before,
                'after' => $after,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadRab($rab->id));
        });
    }

    public function submit(Request $request, string $id)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['PURCHASE_CABANG']);

        $companyId = AuthUser::companyId($request);
        $rab = $this->loadRabGuarded($companyId, $id, $request);

        $rab->load('lineItems');

        if ($rab->status !== 'DRAFT') {
            return response()->json(['message' => 'Only DRAFT RAB can be submitted'], 422);
        }

        if ($rab->lineItems->count() < 1) {
            return response()->json(['message' => 'RAB must have line items'], 422);
        }

        $rab->status = 'SUBMITTED';
        $rab->submitted_at = now();
        $rab->save();

        Audit::log($request, 'submit', 'rab_versions', $rab->id, [
            'from' => 'DRAFT',
            'to' => 'SUBMITTED',
            'submitted_at' => (string) $rab->submitted_at,
            'total' => (float) $rab->total,
            'currency' => $rab->currency,
            'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
        ]);

        return response()->json($this->loadRab($rab->id));
    }

    public function revise(Request $request, string $id)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['PURCHASE_CABANG']);

        $companyId = AuthUser::companyId($request);
        $rab = $this->loadRabGuarded($companyId, $id, $request);
        $rab->load('lineItems');

        if ($rab->status !== 'NEEDS_REVISION') {
            return response()->json(['message' => 'Only NEEDS_REVISION RAB can be revised'], 422);
        }

        return DB::transaction(function () use ($request, $u, $rab) {
            $maxVer = RabVersion::where('purchase_request_id', $rab->purchase_request_id)->max('version_no') ?? 0;
            $newId = (string) Str::uuid();

            $newRab = RabVersion::create([
                'id' => $newId,
                'purchase_request_id' => $rab->purchase_request_id,
                'version_no' => $maxVer + 1,
                'created_by' => $u->id,
                'status' => 'DRAFT',
                'currency' => $rab->currency ?? 'IDR',
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
            ]);

            $items = $rab->lineItems->map(function ($li) {
                return [
                    'item_name' => $li->item_name,
                    'unit' => $li->unit,
                    'qty' => $li->qty,
                    'unit_price' => $li->unit_price,
                ];
            })->all();

            $this->replaceLineItems($newRab, $items);

            Audit::log($request, 'revise', 'rab_versions', $newRab->id, [
                'from_rab_id' => $rab->id,
                'purchase_request_id' => $rab->purchase_request_id,
                'new_version_no' => (int) $newRab->version_no,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadRab($newRab->id), 201);
        });
    }

    private function replaceLineItems(RabVersion $rab, array $lineItems): void
    {
        RabLineItem::where('rab_version_id', $rab->id)->delete();

        $subtotal = 0;

        foreach ($lineItems as $li) {
            $qty = (float) $li['qty'];
            $unitPrice = (float) $li['unit_price'];
            $lineTotal = $qty * $unitPrice;
            $subtotal += $lineTotal;

            RabLineItem::create([
                'id' => (string) Str::uuid(),
                'rab_version_id' => $rab->id,
                'item_name' => $li['item_name'],
                'unit' => $li['unit'],
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]);
        }

        $rab->subtotal = $subtotal;
        $rab->tax = 0;
        $rab->total = $subtotal;
        $rab->save();
    }

    private function loadRab(string $id)
    {
        return RabVersion::with('lineItems')->findOrFail($id);
    }

    private function loadRabGuarded(string $companyId, string $rabId, Request $request)
    {
        $rab = RabVersion::with('lineItems')->findOrFail($rabId);

        $pr = DB::table('purchase_requests as pr')
            ->join('branches as b', 'b.id', '=', 'pr.branch_id')
            ->where('pr.id', $rab->purchase_request_id)
            ->where('b.company_id', $companyId)
            ->select('pr.branch_id')
            ->first();

        if (!$pr) abort(404, 'Not found');

        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array($pr->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

        return $rab;
    }
}
