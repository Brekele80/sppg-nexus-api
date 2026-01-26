<?php

namespace App\Domain\Inventory;

use App\Domain\Accounting\GoodsReceiptAccountingService;
use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GoodsReceiptPostingService
{
    /**
     * Receive/Post a SUBMITTED Goods Receipt into:
     * - inventory_lots (FIFO truth)
     * - inventory_movements (canonical ledger, signed qty)
     * - inventory_items.on_hand (cached projection recomputed from lots INSIDE TX)
     *
     * Canonical ledger invariant:
     * - type: IN|OUT
     * - qty:  IN => +, OUT => -
     *
     * IMPORTANT (your schema reality):
     * - goods_receipts has NO company_id column; tenant must be validated via branches.company_id.
     * - goods_receipts uses gr_number (not receipt_no).
     * - goods_receipt_items has no line_no; we will order by created_at,id for determinism.
     */
    public function receive(string $grId, Request $request): array
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($grId, $request, $u, $companyId, $idempotencyKey) {

            // Lock GR header + join branches to fetch company_id deterministically
            $gr = DB::table('goods_receipts as gr')
                ->join('branches as b', 'b.id', '=', 'gr.branch_id')
                ->where('gr.id', (string) $grId)
                ->select([
                    'gr.*',
                    'b.company_id as company_id',
                ])
                ->lockForUpdate()
                ->first();

            if (!$gr) {
                throw new HttpException(404, 'Goods receipt not found');
            }

            // Tenant validation (authoritative)
            if ((string) $gr->company_id !== (string) $companyId) {
                throw new HttpException(403, 'Forbidden');
            }

            // Branch access for user (defense-in-depth)
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string) $gr->branch_id, $allowed, true)) {
                throw new HttpException(403, 'No access to this branch');
            }

            // Idempotent: if already terminal, do NOT repost inventory nor accounting
            $status = (string) $gr->status;
            if (in_array($status, ['RECEIVED', 'DISCREPANCY'], true)) {
                return $this->buildResponse((string) $gr->id, (string) $companyId);
            }

            // Gate: only SUBMITTED can be received
            if ($status !== 'SUBMITTED') {
                throw new HttpException(409, 'Only SUBMITTED goods receipts can be received');
            }

            // Load GR items in stable order; lock items so quantities cannot change mid-post
            $items = DB::table('goods_receipt_items as gri')
                ->where('gri.goods_receipt_id', (string) $gr->id)
                ->orderBy('gri.created_at')
                ->orderBy('gri.id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['No receipt items'],
                ]);
            }

            // Preload PO currency + item unit_price for costing
            $po = DB::table('purchase_orders as po')
                ->join('branches as b', 'b.id', '=', 'po.branch_id')
                ->where('po.id', (string) $gr->purchase_order_id)
                ->select([
                    'po.id',
                    'po.currency',
                    'b.company_id as company_id',
                ])
                ->first();

            if (!$po || (string) $po->company_id !== (string) $companyId) {
                throw new HttpException(403, 'Forbidden');
            }

            $currency = $po->currency ?: 'IDR';

            $poItemPrices = DB::table('purchase_order_items')
                ->where('purchase_order_id', (string) $gr->purchase_order_id)
                ->select(['id', 'unit_price'])
                ->get()
                ->keyBy('id');

            $now = now();

            $movementIds = [];
            $lotIds = [];
            $linesForAudit = [];

            $seq = 1; // deterministic per-GR lot code sequence

            foreach ($items as $it) {

                $itemName = trim((string) $it->item_name);
                if ($itemName === '') {
                    throw ValidationException::withMessages([
                        "items.item_name" => ['item_name is required'],
                    ]);
                }

                $unit = $it->unit !== null ? trim((string) $it->unit) : null;
                if ($unit === '') $unit = null;

                $qty = $this->dec3((string) $it->received_qty);
                if (bccomp($qty, '0.000', 3) <= 0) {
                    throw ValidationException::withMessages([
                        "items.received_qty" => ['received_qty must be > 0'],
                    ]);
                }

                // Resolve inventory item (prefer inventory_item_id; else by (branch, name, unit))
                $invItem = null;

                if (!empty($it->inventory_item_id)) {
                    $invItemId = (string) $it->inventory_item_id;

                    $invItem = DB::table('inventory_items')
                        ->where('id', $invItemId)
                        ->where('branch_id', (string) $gr->branch_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$invItem) {
                        throw ValidationException::withMessages([
                            "items.inventory_item_id" => ['inventory_item_id not found in this branch'],
                        ]);
                    }

                    // Guard mismatch
                    $expectedName = (string) $invItem->item_name;
                    $expectedUnit = $invItem->unit !== null ? (string) $invItem->unit : null;

                    if ($expectedName !== $itemName) {
                        throw ValidationException::withMessages([
                            "items.item_name" => ["item_name mismatch for inventory_item_id (expected: {$expectedName})"],
                        ]);
                    }
                    if (($expectedUnit ?? null) !== ($unit ?? null)) {
                        $eu = $expectedUnit ?? 'NULL';
                        $uu = $unit ?? 'NULL';
                        throw ValidationException::withMessages([
                            "items.unit" => ["unit mismatch for inventory_item_id (expected: {$eu}, got: {$uu})"],
                        ]);
                    }
                } else {
                    $invItem = DB::table('inventory_items')
                        ->where('branch_id', (string) $gr->branch_id)
                        ->where('item_name', $itemName)
                        ->where(function ($q) use ($unit) {
                            if ($unit === null) $q->whereNull('unit');
                            else $q->where('unit', $unit);
                        })
                        ->lockForUpdate()
                        ->first();
                }

                // Create inventory item if not found (IN flow)
                if (!$invItem) {
                    $invItemId = (string) Str::uuid();

                    DB::table('inventory_items')->insert([
                        'id'         => $invItemId,
                        'branch_id'  => (string) $gr->branch_id,
                        'item_name'  => $itemName,
                        'unit'       => $unit,
                        'on_hand'    => '0.000',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $invItem = DB::table('inventory_items')
                        ->where('id', $invItemId)
                        ->where('branch_id', (string) $gr->branch_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$invItem) {
                        throw new HttpException(500, 'Failed to create inventory item');
                    }
                }

                // Persist resolved inventory_item_id back to GR item (traceability)
                DB::table('goods_receipt_items')
                    ->where('id', (string) $it->id)
                    ->update([
                        'inventory_item_id' => (string) $invItem->id,
                        'updated_at'        => $now,
                    ]);

                $before = $this->sumLots((string) $gr->branch_id, (string) $invItem->id);

                // One line => one lot; deterministic
                $lotId = (string) Str::uuid();

                $grNumber = (string) $gr->gr_number;
                if ($grNumber === '') {
                    throw new HttpException(500, 'goods_receipts.gr_number is required');
                }

                $lotCode = 'LOT-' . $grNumber . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);

                $unitCost = $this->resolveUnitCost($poItemPrices, (string) $it->purchase_order_item_id);

                DB::table('inventory_lots')->insert([
                    'id'                    => $lotId,
                    'branch_id'             => (string) $gr->branch_id,
                    'inventory_item_id'     => (string) $invItem->id,
                    'goods_receipt_id'      => (string) $gr->id,
                    'goods_receipt_item_id' => (string) $it->id,
                    'lot_code'              => $lotCode,
                    'expiry_date'           => null,
                    'received_qty'          => $qty,
                    'remaining_qty'         => $qty,
                    'unit_cost'             => $unitCost,
                    'currency'              => $currency,
                    'received_at'           => $now,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]);

                // Movement (IN => signed +)
                $moveId = (string) Str::uuid();

                DB::table('inventory_movements')->insert([
                    'id'                => $moveId,
                    'branch_id'         => (string) $gr->branch_id,
                    'inventory_item_id' => (string) $invItem->id,
                    'type'              => 'IN',
                    'qty'               => $qty,
                    'inventory_lot_id'  => $lotId,

                    'source_type'       => 'GOODS_RECEIPT',
                    'source_id'         => (string) $gr->id,

                    'ref_type'          => 'goods_receipts',
                    'ref_id'            => (string) $gr->id,

                    'actor_id'          => (string) $u->id,
                    'note'              => $gr->notes ?? null,

                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);

                // Recompute cached on_hand from lots (truth) inside same TX
                $after = $this->recomputeOnHandFromLots((string) $gr->branch_id, (string) $invItem->id);

                $movementIds[] = $moveId;
                $lotIds[] = $lotId;

                $linesForAudit[] = [
                    'seq'                    => $seq,
                    'goods_receipt_item_id'   => (string) $it->id,
                    'purchase_order_item_id'  => (string) $it->purchase_order_item_id,
                    'inventory_item_id'       => (string) $invItem->id,
                    'item_name'              => $itemName,
                    'unit'                   => $unit,
                    'qty'                    => $qty,
                    'on_hand_before'         => $before,
                    'on_hand_after'          => $after,
                    'lot_id'                 => $lotId,
                    'lot_code'               => $lotCode,
                    'movement_id'            => $moveId,
                ];

                $seq++;
            }

            // Finalize GR header (still inside TX)
            DB::table('goods_receipts')
                ->where('id', (string) $gr->id)
                ->update([
                    'status'               => 'RECEIVED',
                    'received_at'          => $now,
                    'received_by'          => (string) $u->id,
                    'inventory_posted'     => true,
                    'inventory_posted_at'  => $now,
                    'updated_at'           => $now,
                ]);

            // Auto-post accounting journal (atomic with inventory posting)
            $acctSvc = app(GoodsReceiptAccountingService::class);
            $acctRes = $acctSvc->postForGoodsReceipt((string) $gr->id, $request);

            $accountingJournalId = $this->extractJournalId($acctRes);

            Audit::log($request, 'receive', 'goods_receipts', (string) $gr->id, [
                'gr_number'              => (string) $gr->gr_number,
                'branch_id'              => (string) $gr->branch_id,
                'lot_ids'                => $lotIds,
                'movement_ids'           => $movementIds,
                'lines'                  => $linesForAudit,
                'accounting_journal_id'  => $accountingJournalId,
                'idempotency_key'        => $idempotencyKey,
            ]);

            return $this->buildResponse((string) $gr->id, (string) $companyId);
        });
    }

    private function buildResponse(string $grId, string $companyId): array
    {
        $gr = DB::table('goods_receipts as gr')
            ->join('branches as b', 'b.id', '=', 'gr.branch_id')
            ->where('gr.id', $grId)
            ->select(['gr.*', 'b.company_id as company_id'])
            ->first();

        if (!$gr) {
            throw new HttpException(404, 'Goods receipt not found');
        }
        if ((string) $gr->company_id !== (string) $companyId) {
            throw new HttpException(403, 'Forbidden');
        }

        $items = DB::table('goods_receipt_items')
            ->where('goods_receipt_id', $grId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $lots = DB::table('inventory_lots')
            ->where('goods_receipt_id', $grId)
            ->orderBy('created_at')
            ->get();

        return [
            'id'                  => (string) $gr->id,
            'company_id'          => (string) $gr->company_id,
            'branch_id'           => (string) $gr->branch_id,
            'purchase_order_id'   => (string) $gr->purchase_order_id,
            'gr_number'           => (string) $gr->gr_number,
            'status'              => (string) $gr->status,
            'received_at'         => $gr->received_at,
            'received_by'         => $gr->received_by,
            'inventory_posted'    => (bool) $gr->inventory_posted,
            'inventory_posted_at' => $gr->inventory_posted_at,
            'items'               => $items,
            'lots'                => $lots,
        ];
    }

    private function resolveUnitCost($poItemPrices, string $purchaseOrderItemId): string
    {
        $row = $poItemPrices[$purchaseOrderItemId] ?? null;
        if (!$row) return '0';
        return (string) ($row->unit_price ?? 0);
    }

    private function sumLots(string $branchId, string $inventoryItemId): string
    {
        $row = DB::selectOne(
            "select coalesce(sum(remaining_qty), 0) as lots_sum
             from inventory_lots
             where branch_id = ? and inventory_item_id = ?",
            [$branchId, $inventoryItemId]
        );

        return $this->dec3((string) ($row->lots_sum ?? '0'));
    }

    private function recomputeOnHandFromLots(string $branchId, string $inventoryItemId): string
    {
        $sum = $this->sumLots($branchId, $inventoryItemId);

        DB::update(
            "update inventory_items
             set on_hand = ?, updated_at = ?
             where id = ? and branch_id = ?",
            [$sum, now(), $inventoryItemId, $branchId]
        );

        return $sum;
    }

    /**
     * Extract journal_id from any reasonable return type:
     * - string journal uuid
     * - ['journal_id' => '...']
     * - ['journal' => (object|array with id)]
     * - stdClass with ->id or ->journal_id
     */
    private function extractJournalId($acctRes): string
    {
        if (is_string($acctRes)) {
            return $acctRes;
        }

        if (is_array($acctRes)) {
            if (!empty($acctRes['journal_id'])) return (string) $acctRes['journal_id'];

            if (!empty($acctRes['journal'])) {
                $j = $acctRes['journal'];
                if (is_object($j) && isset($j->id)) return (string) $j->id;
                if (is_array($j) && isset($j['id'])) return (string) $j['id'];
            }

            if (!empty($acctRes['id'])) return (string) $acctRes['id'];
        }

        if (is_object($acctRes)) {
            if (isset($acctRes->journal_id)) return (string) $acctRes->journal_id;
            if (isset($acctRes->id)) return (string) $acctRes->id;
            if (isset($acctRes->journal) && is_object($acctRes->journal) && isset($acctRes->journal->id)) {
                return (string) $acctRes->journal->id;
            }
        }

        return '';
    }

    /**
     * Normalize decimal to scale(3) string (safe for signed numeric strings).
     */
    private function dec3(string $n): string
    {
        $n = trim($n);
        if ($n === '' || !is_numeric($n)) return '0.000';

        $neg = false;
        if (str_starts_with($n, '-')) {
            $neg = true;
            $n = substr($n, 1);
        }

        $parts = explode('.', $n, 2);
        $int = preg_replace('/[^0-9]/', '', $parts[0] ?? '0');
        if ($int === '') $int = '0';

        $dec = preg_replace('/[^0-9]/', '', $parts[1] ?? '0');
        $dec = substr(str_pad($dec, 3, '0'), 0, 3);

        $out = $int . '.' . $dec;
        return $neg && $out !== '0.000' ? '-' . $out : $out;
    }
}
