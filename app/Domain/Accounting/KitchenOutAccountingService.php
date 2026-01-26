<?php

namespace App\Domain\Accounting;

use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KitchenOutAccountingService
{
    /**
     * Journal for POSTED kitchen_out derived from actual FIFO movements.
     *
     * For each OUT movement (per lot):
     *  value = abs(qty) * lot.unit_cost
     *  DR COGS(5100), CR Inventory(1300)
     */
    public function postForKitchenOut(string $kitchenOutId, Request $request): array
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($kitchenOutId, $request, $u, $companyId, $idempotencyKey) {

            $ko = DB::table('kitchen_outs')
                ->where('id', $kitchenOutId)
                ->lockForUpdate()
                ->first();

            if (!$ko) throw new HttpException(404, 'Kitchen out not found');

            // Tenant boundary via branch
            $branchOk = DB::table('branches')
                ->where('id', (string) $ko->branch_id)
                ->where('company_id', (string) $companyId)
                ->exists();

            if (!$branchOk) throw new HttpException(404, 'Not found');

            // Branch entitlement
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string) $ko->branch_id, $allowed, true)) {
                throw new HttpException(403, 'No access to this branch');
            }

            if ((string) ($ko->status ?? '') !== 'POSTED') {
                throw new HttpException(409, 'Kitchen out must be POSTED before accounting post');
            }

            // Idempotency: already exists
            $existing = DB::table('accounting_journals')
                ->where('company_id', (string) $companyId)
                ->where('source_type', 'KITCHEN_OUT')
                ->where('source_id', (string) $ko->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->buildResponse((string) $existing->id, (string) $companyId);
            }

            // Pull movements created by kitchen out (truth of FIFO)
            $moves = DB::table('inventory_movements')
                ->where('source_type', 'KITCHEN_OUT')
                ->where('source_id', (string) $ko->id)
                ->where('type', 'OUT')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($moves->isEmpty()) {
                throw ValidationException::withMessages([
                    'kitchen_out_id' => ['No inventory movements found for kitchen out'],
                ]);
            }

            $inventoryAccountId = AccountingCoaResolver::accountId((string) $companyId, '1300');
            $cogsAccountId      = AccountingCoaResolver::accountId((string) $companyId, '5100');

            $now = now();
            $journalId = (string) Str::uuid();
            $currency = 'IDR';
            $fxRate = '1.000000';

            $lines = [];
            $total = '0.000';
            $lineNo = 1;

            foreach ($moves as $mv) {
                $lotId = (string) ($mv->inventory_lot_id ?? '');
                if ($lotId === '') {
                    throw new HttpException(500, 'Kitchen OUT movement missing inventory_lot_id');
                }

                $lot = DB::table('inventory_lots')
                    ->where('id', $lotId)
                    ->lockForUpdate()
                    ->first();

                if (!$lot) {
                    throw new HttpException(500, "Lot {$lotId} not found for movement");
                }

                $unitCost = $this->dec6((string) ($lot->unit_cost ?? '0'));
                $qtyAbs = $this->abs3((string) $mv->qty); // movement qty is negative
                if (bccomp($qtyAbs, '0.000', 3) <= 0) continue;

                $amount = $this->mul3x6_to3($qtyAbs, $unitCost);
                if (bccomp($amount, '0.000', 3) <= 0) continue;

                $total = bcadd($total, $amount, 3);

                // DR COGS
                $lines[] = [
                    'id'                   => (string) Str::uuid(),
                    'journal_id'            => $journalId,
                    'company_id'            => (string) $companyId,
                    'branch_id'             => (string) $ko->branch_id,
                    'line_no'               => $lineNo++,
                    'account_id'            => $cogsAccountId,
                    'dc'                    => 'D',
                    'amount'                => $amount,
                    'description'           => 'Kitchen OUT COGS lot: ' . (string) ($lot->lot_code ?? ''),
                    'inventory_lot_id'      => $lotId,
                    'inventory_item_id'     => $mv->inventory_item_id ? (string) $mv->inventory_item_id : null,
                    'inventory_movement_id' => (string) $mv->id,
                    'qty'                   => $qtyAbs,
                    'unit_cost'             => $unitCost,
                    'currency'              => $currency,
                    'fx_rate'               => $fxRate,
                    'meta'                  => null,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ];

                // CR Inventory
                $lines[] = [
                    'id'                   => (string) Str::uuid(),
                    'journal_id'            => $journalId,
                    'company_id'            => (string) $companyId,
                    'branch_id'             => (string) $ko->branch_id,
                    'line_no'               => $lineNo++,
                    'account_id'            => $inventoryAccountId,
                    'dc'                    => 'C',
                    'amount'                => $amount,
                    'description'           => 'Kitchen OUT Inventory lot: ' . (string) ($lot->lot_code ?? ''),
                    'inventory_lot_id'      => $lotId,
                    'inventory_item_id'     => $mv->inventory_item_id ? (string) $mv->inventory_item_id : null,
                    'inventory_movement_id' => (string) $mv->id,
                    'qty'                   => $qtyAbs,
                    'unit_cost'             => $unitCost,
                    'currency'              => $currency,
                    'fx_rate'               => $fxRate,
                    'meta'                  => null,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ];
            }

            if (bccomp($total, '0.000', 3) <= 0) {
                throw ValidationException::withMessages([
                    'movements' => ['Total kitchen out value computed as 0; check unit_cost and movement qty'],
                ]);
            }

            DB::table('accounting_journals')->insert([
                'id'                 => $journalId,
                'company_id'          => (string) $companyId,
                'branch_id'           => (string) $ko->branch_id,
                'journal_date'        => $now->toDateString(),
                'source_type'         => 'KITCHEN_OUT',
                'source_id'           => (string) $ko->id,
                'ref_type'            => 'kitchen_outs',
                'ref_id'              => (string) $ko->id,
                'status'              => 'POSTED',
                'currency'            => $currency,
                'fx_rate'             => $fxRate,
                'memo'                => 'Auto-post Kitchen OUT ' . (string) ($ko->out_number ?? ''),
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
                'source_type'      => 'KITCHEN_OUT',
                'source_id'        => (string) $ko->id,
                'out_number'       => (string) ($ko->out_number ?? ''),
                'branch_id'        => (string) $ko->branch_id,
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

    private function abs3(string $n): string
    {
        $n = $this->dec3($n);
        return str_starts_with($n, '-') ? substr($n, 1) : $n;
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

    private function mul3x6_to3(string $qty3, string $unitCost6): string
    {
        $p = bcmul($qty3, $unitCost6, 9);
        return bcadd($p, '0', 3);
    }
}
