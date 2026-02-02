<?php

namespace App\Http\Controllers\DC;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuForecastController extends Controller
{
    /**
     * Forecast inventory + supplier impact for a menu
     *
     * POST /dc/menus/{menu}/forecast
     * {
     *   "days": 7,
     *   "meals_per_day": 300
     * }
     */
    public function forecast(Request $request, string $menuId)
    {
        $companyId = $request->attributes->get('company_id');
        $branchId = $request->attributes->get('branch_id');

        $data = $request->validate([
            'days' => 'required|integer|min:1|max:90',
            'meals_per_day' => 'required|integer|min:1|max:100000'
        ]);

        $days = $data['days'];
        $mealsPerDay = $data['meals_per_day'];
        $totalServings = $days * $mealsPerDay;

        /**
         * Pull ingredient demand
         */
        $ingredients = DB::table('menu_recipes as mr')
            ->join('recipe_ingredients as ri', 'ri.recipe_id', '=', 'mr.recipe_id')
            ->join('inventory_items as ii', 'ii.id', '=', 'ri.inventory_item_id')
            ->where('mr.menu_id', $menuId)
            ->where('ii.company_id', $companyId)
            ->where('ii.branch_id', $branchId)
            ->groupBy(
                'ri.inventory_item_id',
                'ii.name',
                'ri.unit'
            )
            ->select([
                'ri.inventory_item_id',
                'ii.name as ingredient_name',
                'ri.unit',
                DB::raw('SUM(ri.qty_per_serving * mr.servings) as per_meal_qty')
            ])
            ->get();

        if ($ingredients->isEmpty()) {
            abort(400, 'Menu has no ingredients');
        }

        $results = [];
        $totals = [
            'total_items' => 0,
            'missing_supplier_mappings' => 0,
            'insufficient_fifo_stock' => 0
        ];

        foreach ($ingredients as $row) {
            $requiredQty = $row->per_meal_qty * $totalServings;

            /**
             * FIFO availability
             */
            $fifoLots = DB::table('inventory_lots')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('inventory_item_id', $row->inventory_item_id)
                ->where('remaining_qty', '>', 0)
                ->orderBy('received_at')
                ->get();

            $availableQty = $fifoLots->sum('remaining_qty');
            $fifoSufficient = $availableQty >= $requiredQty;

            /**
             * Supplier mapping
             */
            $supplierMap = DB::table('ingredient_supplier_maps as ism')
                ->join('suppliers as s', 's.id', '=', 'ism.supplier_id')
                ->where('ism.company_id', $companyId)
                ->where('ism.branch_id', $branchId)
                ->where('ism.inventory_item_id', $row->inventory_item_id)
                ->where('ism.is_preferred', true)
                ->select([
                    's.id as supplier_id',
                    's.name as supplier_name',
                    'ism.supplier_sku',
                    'ism.supplier_unit',
                    'ism.unit_conversion_factor'
                ])
                ->first();

            if (!$supplierMap) {
                $totals['missing_supplier_mappings']++;
            }

            if (!$fifoSufficient) {
                $totals['insufficient_fifo_stock']++;
            }

            $results[] = [
                'inventory_item_id' => $row->inventory_item_id,
                'ingredient_name' => $row->ingredient_name,
                'unit' => $row->unit,

                'forecast' => [
                    'days' => $days,
                    'meals_per_day' => $mealsPerDay,
                    'total_servings' => $totalServings,
                    'required_qty' => round($requiredQty, 4)
                ],

                'fifo' => [
                    'available_qty' => round($availableQty, 4),
                    'sufficient' => $fifoSufficient,
                    'lots' => $fifoLots->map(function ($lot) {
                        return [
                            'lot_id' => $lot->id,
                            'remaining_qty' => round($lot->remaining_qty, 4),
                            'received_at' => $lot->received_at
                        ];
                    })
                ],

                'supplier' => $supplierMap ? [
                    'supplier_id' => $supplierMap->supplier_id,
                    'supplier_name' => $supplierMap->supplier_name,
                    'sku' => $supplierMap->supplier_sku,
                    'unit' => $supplierMap->supplier_unit,
                    'conversion_factor' => $supplierMap->unit_conversion_factor,
                    'required_supplier_qty' => round(
                        $requiredQty * $supplierMap->unit_conversion_factor,
                        4
                    )
                ] : null
            ];

            $totals['total_items']++;
        }

        return response()->json([
            'menu_id' => $menuId,
            'company_id' => $companyId,
            'branch_id' => $branchId,

            'parameters' => [
                'days' => $days,
                'meals_per_day' => $mealsPerDay,
                'total_servings' => $totalServings
            ],

            'summary' => $totals,
            'items' => $results
        ]);
    }
}
