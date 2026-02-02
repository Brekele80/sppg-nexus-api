<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuAutoPrService
{
    public static function generate(
        string $companyId,
        string $branchId,
        string $menuId,
        int $days,
        int $mealsPerDay,
        string $userId
    ) {
        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $menuId,
            $days,
            $mealsPerDay,
            $userId
        ) {
            $forecast = MenuForecastService::forecast(
                $menuId,
                $days,
                $mealsPerDay
            );

            if (empty($forecast)) {
                throw new \RuntimeException("No shortages detected. PR not required.");
            }

            $pr = PurchaseRequest::create([
                'id' => Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'status' => 'DRAFT',
                'created_by' => $userId,
                'source_type' => 'MENU_FORECAST',
                'source_id' => $menuId
            ]);

            foreach ($forecast as $row) {
                DB::table('purchase_request_items')->insert([
                    'id' => Str::uuid(),
                    'purchase_request_id' => $pr->id,
                    'inventory_item_id' => $row->inventory_item_id,
                    'qty' => $row->shortage_qty,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            return $pr;
        });
    }
}
