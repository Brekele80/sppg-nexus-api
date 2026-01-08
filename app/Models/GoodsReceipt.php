<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GoodsReceipt extends Model
{
    use HasUuids;

    // Make UUID PK behavior explicit
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'branch_id',
        'purchase_order_id',
        'gr_number',
        'status',
        'created_by',
        'submitted_by',
        'received_by',
        'submitted_at',
        'received_at',
        'notes',
        'meta',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'received_at' => 'datetime',
        'meta' => 'array',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function items()
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }

    public function events()
    {
        return $this->hasMany(GoodsReceiptEvent::class, 'goods_receipt_id');
    }
}
