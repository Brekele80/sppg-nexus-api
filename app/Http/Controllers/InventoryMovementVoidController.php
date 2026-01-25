<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryMovementVoidController extends Controller
{
    public function void(Request $request, string $id)
    {
        $orig = InventoryMovement::where('id', $id)->lockForUpdate()->firstOrFail();

        DB::transaction(function () use ($orig, $request) {

            DB::table('inventory_movements')->insert([
                'branch_id'         => $orig->branch_id,
                'inventory_item_id'=> $orig->inventory_item_id,
                'inventory_lot_id' => $orig->inventory_lot_id,
                'type'             => $orig->type === 'IN' ? 'OUT' : 'IN',
                'qty'              => $orig->qty,
                'source_type'      => $orig->source_type . '_VOID',
                'source_id'        => $orig->id,
                'note'             => 'VOID of movement ' . $orig->id,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            DB::table('inventory_lots')
                ->where('id', $orig->inventory_lot_id)
                ->update([
                    'remaining_qty' => DB::raw("remaining_qty " .
                        ($orig->type === 'IN' ? '-' : '+') . " {$orig->qty}")
                ]);
        });

        return ['status' => 'voided'];
    }
}
