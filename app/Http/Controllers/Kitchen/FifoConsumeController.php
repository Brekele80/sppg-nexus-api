<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FifoConsumeController extends Controller
{
    public function consume(Request $request)
    {
        $companyId = $request->company_id;
        $branchId = $request->branch_id;

        $data = $request->validate([
            'inventory_item_id' => 'required|uuid',
            'qty' => 'required|numeric|min:0.001'
        ]);

        return DB::transaction(function () use ($data, $companyId, $branchId) {
            $remaining = $data['qty'];

            $lots = DB::table('inventory_lots')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('inventory_item_id', $data['inventory_item_id'])
                ->where('remaining_qty', '>', 0)
                ->orderBy('received_at')
                ->lockForUpdate()
                ->get();

            foreach ($lots as $lot) {
                if ($remaining <= 0) break;

                $consume = min($remaining, $lot->remaining_qty);

                DB::table('inventory_lots')
                    ->where('id', $lot->id)
                    ->update([
                        'remaining_qty' => DB::raw("remaining_qty - $consume"),
                        'updated_at' => now()
                    ]);

                DB::table('inventory_movements')->insert([
                    'id' => Str::uuid(),
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'inventory_item_id' => $data['inventory_item_id'],
                    'lot_id' => $lot->id,
                    'type' => 'OUT',
                    'qty' => $consume,
                    'source_type' => 'KITCHEN',
                    'source_id' => $branchId,
                    'created_at' => now()
                ]);

                $remaining -= $consume;
            }

            if ($remaining > 0) {
                abort(400, 'Insufficient FIFO inventory');
            }

            return response()->json(['status' => 'ok']);
        });
    }
}
