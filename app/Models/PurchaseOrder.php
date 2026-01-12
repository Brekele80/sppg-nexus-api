<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PurchaseOrder extends Model
{
    use HasUuids;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'id','branch_id','purchase_request_id','rab_version_id','created_by','supplier_id',
        'po_number','status','currency','subtotal','tax','total',
        'sent_at','confirmed_at','delivered_at','notes',

        // payment fields
        'payment_status',
        'payment_proof_path',
        'payment_submitted_at',
        'payment_submitted_by',
        'payment_confirmed_at',
        'payment_confirmed_by',
        'paid_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'delivered_at' => 'datetime',

        'payment_submitted_at' => 'datetime',
        'payment_confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];
}
