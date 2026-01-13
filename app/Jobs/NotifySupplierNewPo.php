<?php

namespace App\Jobs;

use App\Models\PurchaseOrder;
use App\Models\Profile;
use App\Notifications\NewPurchaseOrderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

class NotifySupplierNewPo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $poId) {}

    public function handle()
    {
        $po = PurchaseOrder::find($this->poId);
        if (!$po) return;

        $supplier = Profile::find($po->supplier_id);
        if (!$supplier) return;

        $supplier->notify(new NewPurchaseOrderNotification(
            $po->id,
            $po->po_number,
            $po->currency ?? 'IDR',
            (string)$po->total
        ));
    }
}
