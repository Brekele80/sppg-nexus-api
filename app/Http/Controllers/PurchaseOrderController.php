<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\AuthUser;

class PurchaseOrderController extends Controller
{
    // POST /api/rabs/{rabId}/po
    public function createFromApprovedRab(Request $request, string $rabId)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['PURCHASE_CABANG']);
        AuthUser::requireBranch($u);

        $data = $request->validate([
            'supplier_id' => 'required|uuid',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($u, $rabId, $data) {

            // 1) Load RAB + ensure approved
            $rab = DB::table('rab_versions')->where('id', $rabId)->first();
            if (!$rab) abort(404, 'RAB not found');
            if ($rab->status !== 'APPROVED') abort(422, 'RAB must be APPROVED to create PO');

            // 2) Load PR to enforce branch isolation
            $pr = DB::table('purchase_requests')->where('id', $rab->purchase_request_id)->first();
            if (!$pr) abort(404, 'Purchase Request not found');

            if ($pr->branch_id !== $u->branch_id) abort(403, 'Forbidden (cross-branch)');

            // 3) Supplier must have SUPPLIER role
            $supplierHasRole = DB::table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', $data['supplier_id'])
                ->where('roles.code', 'SUPPLIER')
                ->exists();

            if (!$supplierHasRole) abort(422, 'supplier_id is not a SUPPLIER');

            // 4) Prevent duplicate PO for same approved rab
            $existing = PurchaseOrder::where('rab_version_id', $rabId)->first();
            if ($existing) {
                return response()->json($existing->load('items'), 200);
            }

            // 5) Create PO number (simple, deterministic)
            $poNumber = 'PO-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

            $po = PurchaseOrder::create([
                'branch_id' => $pr->branch_id,
                'purchase_request_id' => $pr->id,
                'rab_version_id' => $rabId,
                'created_by' => $u->id,
                'supplier_id' => $data['supplier_id'],
                'po_number' => $poNumber,
                'status' => 'DRAFT',
                'currency' => $rab->currency ?? 'IDR',
                'subtotal' => $rab->subtotal ?? 0,
                'tax' => $rab->tax ?? 0,
                'total' => $rab->total ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);

            // 6) Copy line items from RAB to PO
            $lines = DB::table('rab_line_items')->where('rab_version_id', $rabId)->get();
            foreach ($lines as $ln) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'item_name' => $ln->item_name,
                    'unit' => $ln->unit,
                    'qty' => $ln->qty,
                    'unit_price' => $ln->unit_price,
                    'line_total' => $ln->line_total,
                ]);
            }

            return response()->json($po->load('items'), 201);
        });
    }

    // POST /api/pos/{id}/send
    public function sendToSupplier(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['PURCHASE_CABANG']);
        AuthUser::requireBranch($u);

        $po = PurchaseOrder::where('id', $id)->firstOrFail();
        if ($po->branch_id !== $u->branch_id) abort(403, 'Forbidden (cross-branch)');

        if ($po->status !== 'DRAFT') abort(422, 'PO must be DRAFT');

        $po->status = 'SENT';
        $po->sent_at = now();
        $po->save();

        DB::table('purchase_order_events')->insert([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $po->id,
            'actor_id' => $u->id,
            'event' => 'SENT',
            'message' => 'PO sent to supplier',
            'metadata' => json_encode([]),
            'created_at' => now(),
        ]);

        return response()->json($po->load('items'), 200);
    }

    // GET /api/pos/{id}
    public function show(Request $request, string $id)
    {
        $u = AuthUser::get($request);

        $po = PurchaseOrder::where('id', $id)->firstOrFail();

        // Normalize roles to a simple array of strings
        $roles = [];
        if (isset($u->roles) && is_array($u->roles)) {
            $roles = $u->roles;
        } elseif (isset($u->roles) && $u->roles instanceof \Illuminate\Support\Collection) {
            $roles = $u->roles->all();
        }

        $isSupplier   = in_array('SUPPLIER', $roles, true);
        $isAccounting = in_array('ACCOUNTING', $roles, true);
        $isDcAdmin    = in_array('DC_ADMIN', $roles, true);
        $isChef       = in_array('CHEF', $roles, true);

        // Supplier can only see their own PO
        if ($isSupplier) {
            if ($po->supplier_id !== $u->id) {
                abort(403, 'Forbidden (not your PO)');
            }
            return response()->json($po->load('items'), 200);
        }

        // Accounting is global read (no branch enforcement)
        if ($isAccounting) {
            return response()->json($po->load('items'), 200);
        }

        // Branch-scoped roles (CHEF/DC_ADMIN) must have branch_id and match
        if ($isChef || $isDcAdmin) {
            if (empty($u->branch_id)) {
                abort(403, 'Forbidden (missing branch_id)');
            }
            if ($po->branch_id !== $u->branch_id) {
                abort(403, 'Forbidden (cross-branch)');
            }
            return response()->json($po->load('items'), 200);
        }

        // Default deny
        abort(403, 'Forbidden');
    }
}
