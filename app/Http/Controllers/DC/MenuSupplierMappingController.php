<?php

namespace App\Http\Controllers\DC;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuSupplierMappingController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->attributes->get('company_id');

        $data = $request->validate([
            'branch_id' => 'required|uuid'
        ]);

        return DB::table('ingredient_supplier_maps as m')
            ->join('inventory_items as i', 'i.id', '=', 'm.inventory_item_id')
            ->join('suppliers as s', 's.id', '=', 'm.supplier_id')
            ->where('m.company_id', $companyId)
            ->where('m.branch_id', $data['branch_id'])
            ->select([
                'm.id',
                'i.name as ingredient_name',
                's.name as supplier_name',
                'm.supplier_sku',
                'm.supplier_unit',
                'm.unit_conversion_factor',
                'm.is_preferred'
            ])
            ->orderBy('i.name')
            ->get();
    }

    public function store(Request $request)
    {
        $companyId = $request->attributes->get('company_id');

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'inventory_item_id' => 'required|uuid',
            'supplier_id' => 'required|uuid',
            'supplier_sku' => 'nullable|string',
            'supplier_unit' => 'required|string',
            'unit_conversion_factor' => 'required|numeric|min:0.0001',
            'is_preferred' => 'boolean'
        ]);

        return DB::transaction(function () use ($companyId, $data) {
            if (!empty($data['is_preferred'])) {
                DB::table('ingredient_supplier_maps')
                    ->where('company_id', $companyId)
                    ->where('branch_id', $data['branch_id'])
                    ->where('inventory_item_id', $data['inventory_item_id'])
                    ->update(['is_preferred' => false]);
            }

            DB::table('ingredient_supplier_maps')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'branch_id' => $data['branch_id'],
                    'inventory_item_id' => $data['inventory_item_id'],
                    'supplier_id' => $data['supplier_id']
                ],
                [
                    'id' => (string) Str::uuid(),
                    'supplier_sku' => $data['supplier_sku'] ?? null,
                    'supplier_unit' => $data['supplier_unit'],
                    'unit_conversion_factor' => $data['unit_conversion_factor'],
                    'is_preferred' => $data['is_preferred'] ?? false,
                    'updated_at' => now(),
                    'created_at' => now()
                ]
            );

            return response()->json(['status' => 'ok']);
        });
    }
}
