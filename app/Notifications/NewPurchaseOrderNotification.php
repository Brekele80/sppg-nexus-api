<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewPurchaseOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $poId,
        public string $poNumber,
        public string $currency,
        public string $total
    ) {}

    public function via($notifiable): array
    {
        // Minimal: DB notifications only
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'event' => 'PO_CREATED',
            'po_id' => $this->poId,
            'po_number' => $this->poNumber,
            'currency' => $this->currency,
            'total' => $this->total,
        ];
    }
}
