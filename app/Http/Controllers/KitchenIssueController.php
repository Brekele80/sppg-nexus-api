<?php

namespace App\Http\Controllers;

use App\Models\IssueRequest;
use App\Models\IssueRequestItem;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\AuthUser;

class KitchenIssueController extends Controller
{
    private function nextIrNumber(): string
    {
        return 'IR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }

    public function create(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF']);

        AuthUser::requireCompanyContext($request);
        $branchId = AuthUser::requireBranchAccess($request);

        $request->validate([
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.unit' => 'nullable|string|max:30',
            'items.*.requested_qty' => 'required|numeric|min:0.001',
            'items.*.remarks' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($request, $u, $branchId) {
            $issue = IssueRequest::create([
                'id'        => (string) Str::uuid(),
                'branch_id' => $branchId,
                'created_by'=> $u->id,
                'status'    => 'DRAFT',
                'ir_number' => $this->nextIrNumber(),
                'notes'     => $request->input('notes'),
            ]);

            foreach ($request->input('items') as $it) {
                IssueRequestItem::create([
                    'id' => (string) Str::uuid(),
                    'issue_request_id' => $issue->id,
                    'inventory_item_id' => null,
                    'item_name' => $it['item_name'],
                    'unit' => $it['unit'] ?? null,
                    'requested_qty' => $it['requested_qty'],
                    'remarks' => $it['remarks'] ?? null,
                ]);
            }

            return response()->json($issue->load('items'), 201);
        });
    }

    public function submit(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowed = AuthUser::allowedBranchIds($request);

        $issue = IssueRequest::with('items')->findOrFail($id);

        $ok = DB::table('branches')->where('id', $issue->branch_id)->where('company_id', $companyId)->exists();
        if (!$ok) abort(404, 'Not found');

        if (!in_array($issue->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

        if ($issue->status !== 'DRAFT') {
            return response()->json(['error'=>['code'=>'issue_invalid_state','message'=>'Must be DRAFT']], 409);
        }

        $issue->status = 'SUBMITTED';
        $issue->submitted_at = now();
        $issue->save();

        return response()->json($issue->fresh()->load('items'));
    }

    public function approve(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowed = AuthUser::allowedBranchIds($request);

        $issue = IssueRequest::with('items')->findOrFail($id);

        $ok = DB::table('branches')->where('id', $issue->branch_id)->where('company_id', $companyId)->exists();
        if (!$ok) abort(404, 'Not found');

        if (!in_array($issue->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

        if ($issue->status !== 'SUBMITTED') {
            return response()->json(['error'=>['code'=>'issue_invalid_state','message'=>'Must be SUBMITTED']], 409);
        }

        foreach ($issue->items as $it) {
            if ((float)$it->approved_qty <= 0) {
                $it->approved_qty = $it->requested_qty;
                $it->save();
            }
        }

        $issue->status = 'APPROVED';
        $issue->approved_at = now();
        $issue->approved_by = $u->id;
        $issue->save();

        return response()->json($issue->fresh('items'));
    }

    public function issue(Request $request, string $id, InventoryService $inv)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowed = AuthUser::allowedBranchIds($request);

        return DB::transaction(function () use ($id, $u, $companyId, $allowed, $inv) {

            $issue = IssueRequest::with('items')
                ->lockForUpdate()
                ->findOrFail($id);

            $ok = DB::table('branches')->where('id', $issue->branch_id)->where('company_id', $companyId)->exists();
            if (!$ok) abort(404, 'Not found');

            if (!in_array($issue->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

            if ($issue->status !== 'APPROVED') {
                return response()->json(['error' => ['code'=>'issue_invalid_state','message'=>'Must be APPROVED']], 409);
            }

            foreach ($issue->items as $it) {
                $approved  = (float) $it->approved_qty;
                $requested = (float) $it->requested_qty;
                $qtyToIssue = $approved > 0 ? $approved : $requested;
                if ($qtyToIssue <= 0) continue;

                $invItem = $inv->ensureItem($issue->branch_id, $it->item_name, $it->unit);

                // FIFO consume (updates lots with lockForUpdate in service)
                $allocs = $inv->fifoConsume($issue->branch_id, $invItem->id, $qtyToIssue);

                // Update snapshot
                $inv->subOnHand($invItem, $qtyToIssue);

                foreach ($allocs as $a) {
                    $lot = $a['lot'];
                    $take = (float) $a['qty'];

                    $it->allocations()->create([
                        'id'                => (string) Str::uuid(),
                        'inventory_item_id' => $invItem->id,
                        'qty'               => $take,
                        'unit_cost'         => (float) $lot->unit_cost,
                    ]);

                    InventoryMovement::create([
                        'id'                => (string) Str::uuid(),
                        'branch_id'         => $issue->branch_id,
                        'inventory_item_id' => $invItem->id,
                        'inventory_lot_id'  => $lot->id,

                        'type' => 'ISSUE_OUT',
                        'qty'  => -1 * $take, // negative OUT (schema)

                        'source_type' => 'ISSUE',
                        'source_id'   => $issue->id,

                        'ref_type' => 'issue_requests',
                        'ref_id'   => $issue->id,

                        'actor_id' => $u->id,
                        'note'     => 'Kitchen issue ' . ($issue->ir_number ?? ''),
                    ]);
                }

                $it->issued_qty = $qtyToIssue;
                $it->inventory_item_id = $invItem->id;
                $it->save();
            }

            $issue->status = 'ISSUED';
            $issue->issued_at = now();
            $issue->issued_by = $u->id;
            $issue->save();

            return response()->json($issue->fresh(['items.allocations']));
        });
    }
}
