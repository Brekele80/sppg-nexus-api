<?php

namespace App\Http\Controllers\Api;

use App\Models\RabLineItem;
use App\Models\RabVersion;
use App\Models\PurchaseRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RabController extends BaseApiController
{
    public function createForPr(Request $request, string $prId)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['PURCHASE_CABANG']);

        $pr = PurchaseRequest::findOrFail($prId);

        $data = $request->validate([
            'currency' => 'nullable|string|max:10',
            'line_items' => 'required|array|min:1',
            'line_items.*.item_name' => 'required|string|max:255',
            'line_items.*.unit' => 'required|string|max:50',
            'line_items.*.qty' => 'required|numeric|min:0.001',
            'line_items.*.unit_price' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($u, $pr, $data) {
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

            return response()->json($this->loadRab($rabId), 201);
        });
    }

    public function show(Request $request, string $id)
    {
        $this->authUser($request);
        return response()->json($this->loadRab($id));
    }

    public function updateDraft(Request $request, string $id)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['PURCHASE_CABANG']);

        $rab = RabVersion::findOrFail($id);
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

        return DB::transaction(function () use ($rab, $data) {
            if (isset($data['currency'])) {
                $rab->currency = $data['currency'];
                $rab->save();
            }
            $this->replaceLineItems($rab, $data['line_items']);
            return response()->json($this->loadRab($rab->id));
        });
    }

    public function submit(Request $request, string $id)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['PURCHASE_CABANG']);

        $rab = RabVersion::with('lineItems')->findOrFail($id);
        if ($rab->status !== 'DRAFT') {
            return response()->json(['message' => 'Only DRAFT RAB can be submitted'], 422);
        }

        if ($rab->lineItems->count() < 1) {
            return response()->json(['message' => 'RAB must have line items'], 422);
        }

        $rab->status = 'SUBMITTED';
        $rab->submitted_at = now();
        $rab->save();

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'actor_id' => $u->id,
            'action' => 'RAB_SUBMITTED',
            'entity_type' => 'RAB_VERSION',
            'entity_id' => $rab->id,
            'metadata' => null,
            'created_at' => now(),
        ]);

        return response()->json($this->loadRab($rab->id));
    }

    public function revise(Request $request, string $id)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['PURCHASE_CABANG']);

        $rab = RabVersion::with('lineItems')->findOrFail($id);
        if ($rab->status !== 'NEEDS_REVISION') {
            return response()->json(['message' => 'Only NEEDS_REVISION RAB can be revised'], 422);
        }

        return DB::transaction(function () use ($u, $rab) {
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

            // Copy line items as baseline
            $items = $rab->lineItems->map(function ($li) {
                return [
                    'item_name' => $li->item_name,
                    'unit' => $li->unit,
                    'qty' => $li->qty,
                    'unit_price' => $li->unit_price,
                ];
            })->all();

            $this->replaceLineItems($newRab, $items);

            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'actor_id' => $u->id,
                'action' => 'RAB_REVISION_CREATED',
                'entity_type' => 'RAB_VERSION',
                'entity_id' => $newRab->id,
                'metadata' => json_encode(['from_rab_id' => $rab->id]),
                'created_at' => now(),
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
}
