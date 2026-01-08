<?php

namespace App\Http\Controllers\Api;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PurchaseRequestController extends BaseApiController
{
    public function store(Request $request)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['CHEF']);

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.unit' => 'required|string|max:50',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.remarks' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($u, $data) {
            $prId = (string) Str::uuid();

            $pr = PurchaseRequest::create([
                'id' => $prId,
                'branch_id' => $data['branch_id'],
                'requested_by' => $u->id,
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $it) {
                PurchaseRequestItem::create([
                    'id' => (string) Str::uuid(),
                    'purchase_request_id' => $prId,
                    'item_name' => $it['item_name'],
                    'unit' => $it['unit'],
                    'qty' => $it['qty'],
                    'remarks' => $it['remarks'] ?? null,
                ]);
            }

            return response()->json($this->loadPr($prId), 201);
        });
    }

    public function index(Request $request)
    {
        $u = $this->authUser($request);

        $q = PurchaseRequest::query();

        // Basic branch scoping (recommended)
        if ($u->branch_id) {
            $q->where('branch_id', $u->branch_id);
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        return response()->json($q->orderByDesc('created_at')->limit(200)->get());
    }

    public function show(Request $request, string $id)
    {
        $this->authUser($request);
        return response()->json($this->loadPr($id));
    }

    public function submit(Request $request, string $id)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['CHEF']);

        $pr = PurchaseRequest::findOrFail($id);
        if ($pr->status !== 'DRAFT') {
            return response()->json(['message' => 'Only DRAFT PR can be submitted'], 422);
        }

        $pr->status = 'SUBMITTED';
        $pr->save();

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'actor_id' => $u->id,
            'action' => 'PR_SUBMITTED',
            'entity_type' => 'PURCHASE_REQUEST',
            'entity_id' => $pr->id,
            'metadata' => null,
            'created_at' => now(),
        ]);

        return response()->json($this->loadPr($id));
    }

    private function loadPr(string $id)
    {
        return PurchaseRequest::with(['items'])->findOrFail($id);
    }
}
