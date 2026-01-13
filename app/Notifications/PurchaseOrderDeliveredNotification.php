<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PurchaseOrderDeliveredNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $poId,
        public string $poNumber,
        public string $branchId,
        public string $currency,
        public string $total
    ) {}

    public function via($notifiable): array
    {
        return ['database']; // all-free
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'PO_DELIVERED',
            'po_id' => $this->poId,
            'po_number' => $this->poNumber,
            'branch_id' => $this->branchId,
            'currency' => $this->currency,
            'total' => $this->total,
            'message' => "PO {$this->poNumber} has been delivered. Payment proof required.",
        ];
    }
}
