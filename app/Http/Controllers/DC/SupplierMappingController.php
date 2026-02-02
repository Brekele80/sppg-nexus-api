<?php

namespace App\Http\Controllers\DC;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierMappingController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->attributes->get('company_id');
        $branchId = $request->query('branch_id');

        return DB::table('ingredient_supplier_maps as m')
            ->join('inventory_items as i', 'i.id', '=', 'm.inventory_item_id')
            ->join('suppliers as s', 's.id', '=', 'm.supplier_id')
            ->where('m.company_id', $companyId)
            ->when($branchId, fn($q) => $q->where('m.branch_id', $branchId))
            ->select([
                'm.id',
                'i.name as ingredient',
                's.name as supplier',
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
            'inventory_item_id' => 'required|integer',
            'supplier_id' => 'required|integer',
            'supplier_sku' => 'nullable|string|max:64',
            'supplier_unit' => 'required|string|max:32',
            'unit_conversion_factor' => 'required|numeric|min:0.000001',
            'is_preferred' => 'boolean'
        ]);

        return DB::transaction(function () use ($data, $companyId) {
            $record = DB::table('ingredient_supplier_maps')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'branch_id' => $data['branch_id'],
                    'inventory_item_id' => $data['inventory_item_id'],
                    'supplier_id' => $data['supplier_id']
                ],
                [
                    'supplier_sku' => $data['supplier_sku'],
                    'supplier_unit' => $data['supplier_unit'],
                    'unit_conversion_factor' => $data['unit_conversion_factor'],
                    'is_preferred' => $data['is_preferred'] ?? true,
                    'updated_at' => now(),
                    'created_at' => now()
                ]
            );

            return response()->json(['status' => 'ok']);
        });
    }
}
