<?php

namespace App\Http\Controllers;

use App\Models\IssueRequest;
use App\Models\IssueRequestItem;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use App\Support\AuthUser;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $companyId = AuthUser::requireCompanyContext($request);
        $branchId  = AuthUser::requireBranchAccess($request);

        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.unit' => 'nullable|string|max:30',
            'items.*.requested_qty' => 'required|numeric|min:0.001',
            'items.*.remarks' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($request, $u, $companyId, $branchId, $data) {
            $issue = IssueRequest::create([
                'id'         => (string) Str::uuid(),
                'branch_id'  => $branchId,
                'created_by' => $u->id,
                'status'     => 'DRAFT',
                'ir_number'  => $this->nextIrNumber(),
                'notes'      => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $it) {
                IssueRequestItem::create([
                    'id'               => (string) Str::uuid(),
                    'issue_request_id' => $issue->id,
                    'inventory_item_id'=> null,
                    'item_name'        => $it['item_name'],
                    'unit'             => $it['unit'] ?? null,
                    'requested_qty'    => $it['requested_qty'],
                    'remarks'          => $it['remarks'] ?? null,
                ]);
            }

            // ✅ Audit create (always once; idempotency middleware prevents replay hitting controller)
            Audit::log($request, 'create', 'issue_requests', $issue->id, [
                'company_id' => (string) $companyId,
                'branch_id'  => (string) $branchId,
                'ir_number'  => (string) ($issue->ir_number ?? ''),
                'items_count'=> count($data['items']),
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($issue->load('items'), 201);
        });
    }

    public function submit(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowed   = AuthUser::allowedBranchIds($request);

        return DB::transaction(function () use ($request, $u, $companyId, $allowed, $id) {
            /** @var IssueRequest $issue */
            $issue = IssueRequest::with('items')
                ->lockForUpdate()
                ->findOrFail($id);

            // Company enforcement via branch join
            $ok = DB::table('branches')
                ->where('id', $issue->branch_id)
                ->where('company_id', $companyId)
                ->exists();
            if (!$ok) abort(404, 'Not found');

            if (!in_array($issue->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

            // ✅ Idempotency-safe: if already submitted (or beyond), return without auditing
            if ($issue->status !== 'DRAFT') {
                return response()->json($issue->fresh()->load('items'), 200);
            }

            $issue->status       = 'SUBMITTED';
            $issue->submitted_at = now();
            $issue->save();

            Audit::log($request, 'submit', 'issue_requests', $issue->id, [
                'from' => 'DRAFT',
                'to'   => 'SUBMITTED',
                'branch_id'  => (string) $issue->branch_id,
                'ir_number'  => (string) ($issue->ir_number ?? ''),
                'lines' => $issue->items->map(fn($it) => [
                    'issue_request_item_id' => (string) $it->id,
                    'item_name' => (string) $it->item_name,
                    'unit' => (string) ($it->unit ?? ''),
                    'requested_qty' => (float) $it->requested_qty,
                ])->values()->all(),
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($issue->fresh()->load('items'), 200);
        });
    }

    public function approve(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowed   = AuthUser::allowedBranchIds($request);

        return DB::transaction(function () use ($request, $u, $companyId, $allowed, $id) {
            /** @var IssueRequest $issue */
            $issue = IssueRequest::with('items')
                ->lockForUpdate()
                ->findOrFail($id);

            $ok = DB::table('branches')
                ->where('id', $issue->branch_id)
                ->where('company_id', $companyId)
                ->exists();
            if (!$ok) abort(404, 'Not found');

            if (!in_array($issue->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

            // ✅ Idempotency-safe: if already approved (or issued), return without auditing
            if ($issue->status !== 'SUBMITTED') {
                return response()->json($issue->fresh()->load('items'), 200);
            }

            // Default approve qty if not set
            foreach ($issue->items as $it) {
                if ((float)($it->approved_qty ?? 0) <= 0) {
                    $it->approved_qty = $it->requested_qty;
                    $it->save();
                }
            }

            $issue->status      = 'APPROVED';
            $issue->approved_at = now();
            $issue->approved_by = $u->id;
            $issue->save();

            Audit::log($request, 'approve', 'issue_requests', $issue->id, [
                'from' => 'SUBMITTED',
                'to'   => 'APPROVED',
                'branch_id'  => (string) $issue->branch_id,
                'ir_number'  => (string) ($issue->ir_number ?? ''),
                'lines' => $issue->items->map(fn($it) => [
                    'issue_request_item_id' => (string) $it->id,
                    'item_name' => (string) $it->item_name,
                    'unit' => (string) ($it->unit ?? ''),
                    'requested_qty' => (float) $it->requested_qty,
                    'approved_qty'  => (float) ($it->approved_qty ?? 0),
                ])->values()->all(),
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($issue->fresh()->load('items'), 200);
        });
    }

    public function issue(Request $request, string $id, InventoryService $inv)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowed   = AuthUser::allowedBranchIds($request);

        return DB::transaction(function () use ($request, $u, $companyId, $allowed, $inv, $id) {
            /** @var IssueRequest $issue */
            $issue = IssueRequest::with('items.allocations')
                ->lockForUpdate()
                ->findOrFail($id);

            $ok = DB::table('branches')
                ->where('id', $issue->branch_id)
                ->where('company_id', $companyId)
                ->exists();
            if (!$ok) abort(404, 'Not found');

            if (!in_array($issue->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

            // ✅ Idempotency-safe: if already issued, return without auditing
            if ($issue->status === 'ISSUED') {
                return response()->json($issue->fresh(['items.allocations']), 200);
            }

            if ($issue->status !== 'APPROVED') {
                return response()->json(['error' => [
                    'code' => 'issue_invalid_state',
                    'message' => 'Must be APPROVED'
                ]], 409);
            }

            $issuedSummary = [];
            $totalIssuedQty = 0.0;

            foreach ($issue->items as $it) {
                $approved  = (float)($it->approved_qty ?? 0);
                $requested = (float)($it->requested_qty ?? 0);
                $qtyToIssue = $approved > 0 ? $approved : $requested;

                if ($qtyToIssue <= 0) continue;

                $invItem = $inv->ensureItem($issue->branch_id, $it->item_name, $it->unit);

                // FIFO consume
                $allocs = $inv->fifoConsume($issue->branch_id, $invItem->id, $qtyToIssue);

                // Update snapshot
                $inv->subOnHand($invItem, $qtyToIssue);

                $allocCount = 0;
                foreach ($allocs as $a) {
                    $lot  = $a['lot'];
                    $take = (float) $a['qty'];
                    $allocCount++;

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
                        'qty'  => -1 * $take,

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

                $totalIssuedQty += $qtyToIssue;

                $issuedSummary[] = [
                    'issue_request_item_id' => (string) $it->id,
                    'inventory_item_id'     => (string) $invItem->id,
                    'item_name'             => (string) $it->item_name,
                    'unit'                  => (string) ($it->unit ?? ''),
                    'qty_issued'            => (float) $qtyToIssue,
                    'allocations_count'     => (int) $allocCount,
                ];
            }

            $issue->status    = 'ISSUED';
            $issue->issued_at = now();
            $issue->issued_by = $u->id;
            $issue->save();

            Audit::log($request, 'issue', 'issue_requests', $issue->id, [
                'from' => 'APPROVED',
                'to'   => 'ISSUED',
                'branch_id' => (string) $issue->branch_id,
                'ir_number' => (string) ($issue->ir_number ?? ''),
                'total_lines' => count($issuedSummary),
                'total_qty_issued' => (float) $totalIssuedQty,
                'lines' => $issuedSummary,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($issue->fresh(['items.allocations']), 200);
        });
    }
}
