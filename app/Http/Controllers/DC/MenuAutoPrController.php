<?php

namespace App\Http\Controllers\DC;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuAutoPrController extends Controller
{
    public function generate(Request $request, string $menuId)
    {
        $companyId = $request->attributes->get('company_id');

        $data = $request->validate([
            'branch_id' => 'required|uuid'
        ]);

        return DB::transaction(function () use ($companyId, $menuId, $data) {
            $branchId = $data['branch_id'];

            // Aggregate menu demand
            $ingredients = DB::table('menu_recipes as mr')
                ->join('recipe_ingredients as ri', 'ri.recipe_id', '=', 'mr.recipe_id')
                ->where('mr.menu_id', $menuId)
                ->select([
                    'ri.inventory_item_id',
                    DB::raw('SUM(ri.qty_per_serving * mr.servings) as total_qty'),
                    'ri.unit'
                ])
                ->groupBy('ri.inventory_item_id', 'ri.unit')
                ->get();

            if ($ingredients->isEmpty()) {
                abort(400, 'Menu has no ingredient demand');
            }

            $prId = DB::table('purchase_requests')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'status' => 'DRAFT',
                'source_type' => 'MENU',
                'source_id' => $menuId,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            foreach ($ingredients as $row) {
                $map = DB::table('ingredient_supplier_maps')
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('inventory_item_id', $row->inventory_item_id)
                    ->where('is_preferred', true)
                    ->first();

                if (!$map) {
                    abort(400, "Missing preferred supplier mapping for inventory_item_id {$row->inventory_item_id}");
                }

                $supplierQty = $row->total_qty * $map->unit_conversion_factor;

                DB::table('purchase_request_items')->insert([
                    'purchase_request_id' => $prId,
                    'inventory_item_id' => $row->inventory_item_id,
                    'supplier_id' => $map->supplier_id,
                    'qty' => $supplierQty,
                    'unit' => $map->supplier_unit,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            DB::table('auto_pr_audits')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'menu_id' => $menuId,
                'purchase_request_id' => $prId,
                'created_at' => now()
            ]);

            return response()->json([
                'status' => 'ok',
                'purchase_request_id' => $prId
            ]);
        });
    }
}
