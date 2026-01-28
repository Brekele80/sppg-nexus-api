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

        // Normalize date range to full-day bounds (forensic safe)
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
                // Immutable forensic audit log
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

                // CSV Header (matches real schema)
                fputcsv($out, [
                    'movement_id',
                    'movement_datetime',
                    'company_id',
                    'branch_id',
                    'inventory_item_id',
                    'item_sku',
                    'item_name',
                    'inventory_lot_id',
                    'reference_type',
                    'reference_id',
                    'direction',
                    'quantity',
                    'unit',
                    'actor_id',
                    'created_at'
                ]);

                $query = DB::table('inventory_movements as m')
                    ->join('inventory_items as i', 'm.inventory_item_id', '=', 'i.id')
                    ->leftJoin('inventory_lots as l', 'm.inventory_lot_id', '=', 'l.id')
                    ->where('m.company_id', $companyId)
                    ->whereBetween('m.created_at', [$fromTs, $toTs])
                    ->orderBy('m.created_at')
                    ->select([
                        'm.id as movement_id',
                        'm.company_id',
                        'm.branch_id',
                        'm.inventory_item_id',
                        'i.sku as item_sku',
                        'i.name as item_name',
                        'm.inventory_lot_id',
                        'm.ref_type',
                        'm.ref_id',
                        'm.type',
                        'm.qty',
                        'i.unit',
                        'm.actor_id',
                        'm.created_at'
                    ]);

                if ($branchId) {
                    $query->where('m.branch_id', $branchId);
                }

                if ($itemId) {
                    $query->where('m.inventory_item_id', $itemId);
                }

                $query->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->movement_id,
                            $row->created_at,
                            $row->company_id,
                            $row->branch_id,
                            $row->inventory_item_id,
                            $row->item_sku,
                            $row->item_name,
                            $row->inventory_lot_id,
                            $row->ref_type,
                            $row->ref_id,
                            $row->type,
                            $row->qty,
                            $row->unit,
                            $row->actor_id,
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
