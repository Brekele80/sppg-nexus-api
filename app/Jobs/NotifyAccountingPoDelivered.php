<?php

namespace App\Jobs;

use App\Models\PurchaseOrder;
use App\Models\Profile;
use App\Notifications\PurchaseOrderDeliveredNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class NotifyAccountingPoDelivered implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $poId) {}

    public function handle(): void
    {
        $po = PurchaseOrder::where('id', $this->poId)->first();
        if (!$po) return;

        // Resolve company_id via branch (since purchase_orders doesn't have company_id yet)
        $companyId = DB::table('branches')->where('id', $po->branch_id)->value('company_id');
        if (!$companyId) return;

        // Find ACCOUNTING profiles in the same company
        $accountingProfileIds = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('profiles', 'profiles.id', '=', 'user_roles.user_id')
            ->where('roles.code', 'ACCOUNTING')
            ->where('profiles.company_id', $companyId)
            ->pluck('profiles.id')
            ->unique()
            ->values()
            ->all();

        if (!$accountingProfileIds) return;

        $profiles = Profile::whereIn('id', $accountingProfileIds)->get();

        foreach ($profiles as $p) {
            $p->notify(new PurchaseOrderDeliveredNotification(
                poId: $po->id,
                poNumber: $po->po_number,
                branchId: $po->branch_id,
                currency: $po->currency ?? 'IDR',
                total: (string) ($po->total ?? 0)
            ));
        }
    }
}
