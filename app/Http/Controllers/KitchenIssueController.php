<?php

namespace App\Http\Controllers;

use App\Models\IssueRequest;
use App\Models\IssueRequestItem;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KitchenIssueController extends Controller
{
    

    private function nextIrNumber(): string
    {
        // Example: IR-20260109-AB12CD (date + random)
        return 'IR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }

    public function create(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        $request->validate([
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.unit' => 'nullable|string|max:30',
            'items.*.requested_qty' => 'required|numeric|min:0.001',
            'items.*.remarks' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($request, $user) {
            $issue = IssueRequest::create([
                'id'        => (string) Str::uuid(),
                'branch_id' => $user->branch_id,
                'created_by'=> $user->id,
                'status'    => 'DRAFT',
                'ir_number' => $this->nextIrNumber(),
                'notes'     => $request->input('notes'),
            ]);

            foreach ($request->input('items') as $it) {
                IssueRequestItem::create([
                    'id' => Str::uuid(),
                    'issue_request_id' => $issue->id,
                    'inventory_item_id' => null,
                    'item_name' => $it['item_name'],
                    'unit' => $it['unit'] ?? null,
                    'requested_qty' => $it['requested_qty'],
                    'remarks' => $it['remarks'] ?? null,
                ]);
            }

            $issue->load('items');
            return response()->json($issue, 201);
        });
    }

    public function submit(Request $request, string $id)
    {
        $user = $request->attributes->get('auth_user');

        $issue = IssueRequest::with('items')->findOrFail($id);
        if ($issue->status !== 'DRAFT') {
            return response()->json(['error'=>['code'=>'issue_invalid_state','message'=>'Must be DRAFT']], 409);
        }

        $issue->status = 'SUBMITTED';
        $issue->submitted_at = now();
        $issue->save();

        $issue->load('items');
        return response()->json($issue);
    }

    // DC role
    public function approve(Request $request, string $id)
    {
        $user = $request->attributes->get('auth_user');

        $issue = IssueRequest::with('items')->findOrFail($id);
        if ($issue->status !== 'SUBMITTED') {
            return response()->json(['error'=>['code'=>'issue_invalid_state','message'=>'Must be SUBMITTED']], 409);
        }

        // Default: approved_qty = requested_qty if not set
        foreach ($issue->items as $it) {
            if ((float)$it->approved_qty <= 0) {
                $it->approved_qty = $it->requested_qty;
                $it->save();
            }
        }

        $issue->status = 'APPROVED';
        $issue->approved_at = now();
        $issue->approved_by = $user?->id; // optional if you track it
        $issue->save();

        return response()->json($issue->fresh('items'));
    }

    // DC role + idempotency
    public function issue(Request $request, string $id, InventoryService $inv)
    {
        $user = $request->attributes->get('auth_user');

        return DB::transaction(function () use ($id, $user, $inv) {

            $issue = IssueRequest::with('items')
                ->lockForUpdate()
                ->findOrFail($id);

            if ($issue->status !== 'APPROVED') {
                return response()->json([
                    'error' => ['code'=>'issue_invalid_state','message'=>'Must be APPROVED']
                ], 409);
            }

            foreach ($issue->items as $it) {

                $approved  = (float) $it->approved_qty;
                $requested = (float) $it->requested_qty;
                $qtyToIssue = $approved > 0 ? $approved : $requested;
                if ($qtyToIssue <= 0) continue;

                // Ensure inventory item exists
                $invItem = $inv->ensureItem($issue->branch_id, $it->item_name, $it->unit);

                // FIFO lot consume
                $allocs = $inv->fifoConsume($issue->branch_id, $invItem->id, $qtyToIssue);

                // Update on hand
                $inv->subOnHand($invItem, $qtyToIssue);

                foreach ($allocs as $a) {
                    $lot = $a['lot'];
                    $take = (float)$a['qty'];

                    // Correct Issue Allocation insert
                    $it->allocations()->create([
                        'id'                => (string) Str::uuid(),
                        'inventory_item_id' => $invItem->id,
                        'qty'               => $take,
                        'unit_cost'         => (float) $lot->unit_cost,
                    ]);

                    // Correct Inventory Movement insert
                    InventoryMovement::create([
                        'id'                => (string) Str::uuid(),
                        'branch_id'         => $issue->branch_id,
                        'inventory_item_id' => $invItem->id,
                        'inventory_lot_id'  => $lot->id,
                        'type'              => 'ISSUE',
                        'qty'               => $take,
                        'ref_type'          => 'ISSUE',
                        'ref_id'            => $issue->id,
                        'source_type'       => 'ISSUE',
                        'source_id'         => $issue->id,
                        'actor_id'          => $user->id,
                        'note'              => 'Kitchen issue',
                    ]);
                }

                $it->issued_qty = $qtyToIssue;
                $it->inventory_item_id = $invItem->id;
                $it->save();
            }

            $issue->status = 'ISSUED';
            $issue->issued_at = now();
            $issue->issued_by = $user->id;
            $issue->save();

            return response()->json($issue->fresh(['items.allocations']));
        });
    }
}
