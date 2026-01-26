<?php

namespace App\Domain\Accounting;

use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GoodsReceiptAccountingService
{
    /**
     * Create accounting journal for a RECEIVED GR (or SUBMITTED being received).
     * Idempotent on (company_id, source_type, source_id).
     *
     * Journal:
     *  DR Inventory(1300) = sum(qty * unit_cost) per FIFO lot from this GR
     *  CR AP(2100)       = same
     */
    public function postForGoodsReceipt(string $grId, Request $request): array
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($grId, $request, $u, $companyId, $idempotencyKey) {
            // Lock GR + branch.company_id
            $gr = DB::table('goods_receipts as gr')
                ->join('branches as b', 'b.id', '=', 'gr.branch_id')
                ->where('gr.id', $grId)
                ->select(['gr.*', 'b.company_id as company_id'])
                ->lockForUpdate()
                ->first();

            if (!$gr) throw new HttpException(404, 'Goods receipt not found');
            if ((string) $gr->company_id !== (string) $companyId) throw new HttpException(404, 'Not found');

            // Branch entitlement defense-in-depth
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string) $gr->branch_id, $allowed, true)) {
                throw new HttpException(403, 'No access to this branch');
            }

            // Gate: only journal when GR is terminal received/discrepancy OR inventory_posted true
            // (Adjust if your business allows journal at SUBMITTED.)
            $status = (string) ($gr->status ?? '');
            $inventoryPosted = (bool) ($gr->inventory_posted ?? false);

            if (!$inventoryPosted && !in_array($status, ['RECEIVED', 'DISCREPANCY'], true)) {
                throw new HttpException(409, 'GR must be RECEIVED (and inventory posted) before accounting post');
            }

            // Idempotency: already exists
            $existing = DB::table('accounting_journals')
                ->where('company_id', (string) $companyId)
                ->where('source_type', 'GOODS_RECEIPT')
                ->where('source_id', (string) $gr->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->buildResponse((string) $existing->id, (string) $companyId);
            }

            // Load lots created by this GR (truth for costing)
            $lots = DB::table('inventory_lots')
                ->where('goods_receipt_id', (string) $gr->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lots->isEmpty()) {
                throw ValidationException::withMessages([
                    'goods_receipt_id' => ['No FIFO lots found for this GR; cannot derive accounting value'],
                ]);
            }

            $currency = (string) ($gr->currency ?? 'IDR'); // if GR has none, you can keep journal currency = IDR
            $fxRate = '1.000000';

            $inventoryAccountId = AccountingCoaResolver::accountId((string) $companyId, '1300');
            $apAccountId        = AccountingCoaResolver::accountId((string) $companyId, '2100');

            $now = now();
            $journalId = (string) Str::uuid();

            // Compute totals and prepare detailed lines
            $lines = [];
            $total = '0.000';

            $lineNo = 1;
            foreach ($lots as $lot) {
                $qty = $this->dec3((string) $lot->received_qty);
                if (bccomp($qty, '0.000', 3) <= 0) continue;

                $unitCost = $this->dec6((string) ($lot->unit_cost ?? '0'));
                $amount = $this->mul3x6_to3($qty, $unitCost);

                if (bccomp($amount, '0.000', 3) <= 0) continue;

                $total = bcadd($total, $amount, 3);

                // DR Inventory per lot (traceable)
                $lines[] = [
                    'id'                  => (string) Str::uuid(),
                    'journal_id'           => $journalId,
                    'company_id'           => (string) $companyId,
                    'branch_id'            => (string) $gr->branch_id,
                    'line_no'              => $lineNo++,
                    'account_id'           => $inventoryAccountId,
                    'dc'                   => 'D',
                    'amount'               => $amount,
                    'description'          => 'GR Inventory lot: ' . (string) ($lot->lot_code ?? ''),
                    'inventory_lot_id'     => (string) $lot->id,
                    'inventory_item_id'    => $lot->inventory_item_id ? (string) $lot->inventory_item_id : null,
                    'inventory_movement_id'=> null,
                    'qty'                  => $qty,
                    'unit_cost'            => $unitCost,
                    'currency'             => $currency,
                    'fx_rate'              => $fxRate,
                    'meta'                 => null,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];
            }

            if (bccomp($total, '0.000', 3) <= 0) {
                throw ValidationException::withMessages([
                    'lots' => ['Total GR value computed as 0; check unit_cost and received_qty'],
                ]);
            }

            // CR Accounts Payable as a single balancing line
            $lines[] = [
                'id'                  => (string) Str::uuid(),
                'journal_id'           => $journalId,
                'company_id'           => (string) $companyId,
                'branch_id'            => (string) $gr->branch_id,
                'line_no'              => $lineNo++,
                'account_id'           => $apAccountId,
                'dc'                   => 'C',
                'amount'               => $total,
                'description'          => 'GR payable: ' . (string) ($gr->gr_number ?? ''),
                'inventory_lot_id'     => null,
                'inventory_item_id'    => null,
                'inventory_movement_id'=> null,
                'qty'                  => null,
                'unit_cost'            => null,
                'currency'             => $currency,
                'fx_rate'              => $fxRate,
                'meta'                 => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];

            DB::table('accounting_journals')->insert([
                'id'                 => $journalId,
                'company_id'          => (string) $companyId,
                'branch_id'           => (string) $gr->branch_id,
                'journal_date'        => $now->toDateString(),
                'source_type'         => 'GOODS_RECEIPT',
                'source_id'           => (string) $gr->id,
                'ref_type'            => 'goods_receipts',
                'ref_id'              => (string) $gr->id,
                'status'              => 'POSTED',
                'currency'            => $currency,
                'fx_rate'             => $fxRate,
                'memo'                => 'Auto-post GR ' . (string) ($gr->gr_number ?? ''),
                'posted_at'           => $now,
                'posted_by'           => (string) $u->id,
                'voided_at'           => null,
                'voided_by'           => null,
                'reversal_of_journal_id' => null,
                'checksum'            => null,
                'idempotency_key'     => $idempotencyKey !== '' ? $idempotencyKey : null,
                'meta'                => null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            DB::table('accounting_journal_lines')->insert($lines);

            Audit::log($request, 'post', 'accounting_journals', $journalId, [
                'source_type'      => 'GOODS_RECEIPT',
                'source_id'        => (string) $gr->id,
                'gr_number'        => (string) ($gr->gr_number ?? ''),
                'branch_id'        => (string) $gr->branch_id,
                'total'            => $total,
                'line_count'       => count($lines),
                'idempotency_key'  => $idempotencyKey,
            ]);

            return $this->buildResponse($journalId, (string) $companyId);
        });
    }

    private function buildResponse(string $journalId, string $companyId): array
    {
        $j = DB::table('accounting_journals')
            ->where('id', $journalId)
            ->where('company_id', $companyId)
            ->first();

        if (!$j) throw new HttpException(404, 'Journal not found');

        $lines = DB::table('accounting_journal_lines')
            ->where('journal_id', $journalId)
            ->orderBy('line_no')
            ->get();

        return [
            'journal' => $j,
            'lines'   => $lines,
        ];
    }

    private function dec3(string $n): string
    {
        $n = trim($n);
        if ($n === '' || !is_numeric($n)) return '0.000';
        $neg = false;
        if (str_starts_with($n, '-')) { $neg = true; $n = substr($n, 1); }

        $parts = explode('.', $n, 2);
        $int = preg_replace('/[^0-9]/', '', $parts[0] ?? '0'); if ($int === '') $int = '0';
        $dec = preg_replace('/[^0-9]/', '', $parts[1] ?? '0');
        $dec = substr(str_pad($dec, 3, '0'), 0, 3);

        $out = $int . '.' . $dec;
        return ($neg && $out !== '0.000') ? '-' . $out : $out;
    }

    private function dec6(string $n): string
    {
        $n = trim($n);
        if ($n === '' || !is_numeric($n)) return '0.000000';
        $neg = false;
        if (str_starts_with($n, '-')) { $neg = true; $n = substr($n, 1); }

        $parts = explode('.', $n, 2);
        $int = preg_replace('/[^0-9]/', '', $parts[0] ?? '0'); if ($int === '') $int = '0';
        $dec = preg_replace('/[^0-9]/', '', $parts[1] ?? '0');
        $dec = substr(str_pad($dec, 6, '0'), 0, 6);

        $out = $int . '.' . $dec;
        return ($neg && $out !== '0.000000') ? '-' . $out : $out;
    }

    /**
     * qty(scale3) * unit_cost(scale6) => amount(scale3)
     */
    private function mul3x6_to3(string $qty3, string $unitCost6): string
    {
        $p = bcmul($qty3, $unitCost6, 9); // keep precision
        return bcadd($p, '0', 3); // truncate/round to scale3
    }
}
