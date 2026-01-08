<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Support\AuthUser;
use App\Services\GoodsReceiptService;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    public function __construct(private GoodsReceiptService $svc) {}

    public function createFromPo(Request $req, PurchaseOrder $po)
    {
        $u = $req->attributes->get('auth_user');
        AuthUser::requireRole($u, ['DC_ADMIN']);
        AuthUser::requireBranch($u);

        // Branch isolation: PO must be in same branch
        if ($po->branch_id !== $u->branch_id) {
            return response()->json(['error' => ['code'=>'forbidden','message'=>'Forbidden']], 403);
        }

        // Require supplier delivered/confirmed before receiving
        if (!in_array($po->status, ['CONFIRMED','DELIVERED'], true)) {
            return response()->json(['error' => ['code'=>'invalid_state','message'=>'PO is not ready for receiving']], 409);
        }

        $po->load('items');

        $gr = $this->svc->createFromPo($po, $u->id);
        return response()->json($gr, 201);
    }

    public function updateItems(Request $req, GoodsReceipt $gr)
    {
        $u = $req->attributes->get('auth_user');
        AuthUser::requireRole($u, ['DC_ADMIN']);
        AuthUser::requireBranch($u);

        if ($gr->branch_id !== $u->branch_id) {
            return response()->json(['error' => ['code'=>'forbidden','message'=>'Forbidden']], 403);
        }

        $data = $req->validate([
            'items' => ['required','array','min:1'],
            'items.*.id' => ['required','uuid'],
            'items.*.received_qty' => ['nullable','numeric','min:0'],
            'items.*.rejected_qty' => ['nullable','numeric','min:0'],
            'items.*.discrepancy_reason' => ['nullable','string','max:255'],
            'items.*.remarks' => ['nullable','string','max:5000'],
        ]);

        $gr = $gr->load('items');
        $updated = $this->svc->updateItems($gr, $data['items'], $u->id);

        return response()->json($updated);
    }

    public function submit(Request $req, GoodsReceipt $gr)
    {
        $u = $req->attributes->get('auth_user');
        AuthUser::requireRole($u, ['DC_ADMIN']);
        AuthUser::requireBranch($u);

        if ($gr->branch_id !== $u->branch_id) {
            return response()->json(['error' => ['code'=>'forbidden','message'=>'Forbidden']], 403);
        }

        $updated = $this->svc->submit($gr, $u->id);
        return response()->json($updated);
    }

    public function receive(Request $req, GoodsReceipt $gr)
    {
        $u = $req->attributes->get('auth_user');
        AuthUser::requireRole($u, ['DC_ADMIN']);
        AuthUser::requireBranch($u);

        if ($gr->branch_id !== $u->branch_id) {
            return response()->json(['error' => ['code'=>'forbidden','message'=>'Forbidden']], 403);
        }

        $gr = $gr->load('items');
        $updated = $this->svc->receive($gr, $u->id);

        return response()->json($updated);
    }

    public function show(Request $req, GoodsReceipt $gr)
    {
        $u = $req->attributes->get('auth_user');
        AuthUser::requireBranch($u);

        // Allow DC_ADMIN + finance/KA roles to view
        AuthUser::requireRole($u, ['DC_ADMIN','KA_SPPG','ACCOUNTING','PURCHASE_CABANG']);

        if ($gr->branch_id !== $u->branch_id) {
            return response()->json(['error' => ['code'=>'forbidden','message'=>'Forbidden']], 403);
        }

        return response()->json($gr->load('items','events'));
    }
}
