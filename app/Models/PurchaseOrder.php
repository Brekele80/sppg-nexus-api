<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PurchaseOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id','purchase_request_id','rab_version_id','created_by','supplier_id',
        'po_number','status','currency','subtotal','tax','total',
        'sent_at','confirmed_at','delivered_at','notes'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
