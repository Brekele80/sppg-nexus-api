<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;

class AuditExportService
{
    public function exportInventoryLedger(
        string $companyId,
        string $from,
        string $to,
        ?string $branchId,
        ?string $itemId,
        string $requestedBy
    ): StreamedResponse {
        $filename = sprintf(
            'inventory_ledger_%s_%s.csv',
            Carbon::parse($from)->format('Ymd'),
            Carbon::parse($to)->format('Ymd')
        );

        // Normalize date range to full-day bounds (audit safe)
        $fromTs = Carbon::parse($from)->startOfDay();
        $toTs   = Carbon::parse($to)->endOfDay();

        return response()->streamDownload(function () use (
            $companyId,
            $from,
            $to,
            $fromTs,
            $toTs,
            $branchId,
            $itemId,
            $requestedBy
        ) {
            DB::transaction(function () use (
                $companyId,
                $from,
                $to,
                $fromTs,
                $toTs,
                $branchId,
                $itemId,
                $requestedBy
            ) {
                // Forensic audit log
                DB::table('audit_exports')->insert([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $companyId,
                    'export_type' => 'INVENTORY_LEDGER',
                    'filters' => json_encode([
                        'from' => $from,
                        'to' => $to,
                        'branch_id' => $branchId,
                        'item_id' => $itemId,
                    ], JSON_THROW_ON_ERROR),
                    'requested_by' => $requestedBy,
                    'created_at' => now(),
                ]);

                $out = fopen('php://output', 'w');

                // CSV Header
                fputcsv($out, [
                    'movement_id',
                    'movement_datetime',
                    'company_id',
                    'branch_id',
                    'item_id',
                    'item_sku',
                    'item_name',
                    'lot_id',
                    'reference_type',
                    'reference_id',
                    'direction',
                    'quantity',
                    'balance_after',
                    'unit',
                    'created_by',
                    'created_at'
                ]);

                $query = DB::table('inventory_movements as m')
                    ->join('inventory_items as i', 'm.item_id', '=', 'i.id')
                    ->leftJoin('inventory_lots as l', 'm.lot_id', '=', 'l.id')
                    ->where('m.company_id', $companyId)
                    ->whereBetween('m.movement_date', [$fromTs, $toTs])
                    ->orderBy('m.movement_date')
                    ->orderBy('m.created_at')
                    ->select([
                        'm.id as movement_id',
                        'm.movement_date',
                        'm.company_id',
                        'm.branch_id',
                        'm.item_id',
                        'i.sku as item_sku',
                        'i.name as item_name',
                        'm.lot_id',
                        'm.reference_type',
                        'm.reference_id',
                        'm.direction',
                        'm.quantity',
                        'm.balance_after',
                        'i.unit',
                        'm.created_by',
                        'm.created_at'
                    ]);

                if ($branchId) {
                    $query->where('m.branch_id', $branchId);
                }

                if ($itemId) {
                    $query->where('m.item_id', $itemId);
                }

                $query->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->movement_id,
                            $row->movement_date,
                            $row->company_id,
                            $row->branch_id,
                            $row->item_id,
                            $row->item_sku,
                            $row->item_name,
                            $row->lot_id,
                            $row->reference_type,
                            $row->reference_id,
                            $row->direction,
                            $row->quantity,
                            $row->balance_after,
                            $row->unit,
                            $row->created_by,
                            $row->created_at,
                        ]);
                    }
                });

                fclose($out);
            });
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
