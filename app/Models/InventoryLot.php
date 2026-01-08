<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InventoryLot extends Model
{
    use HasUuids;

    protected $table = 'inventory_lots';

    protected $fillable = [
        'id','branch_id','inventory_item_id',
        'goods_receipt_id','goods_receipt_item_id',
        'lot_code','expiry_date',
        'received_qty','remaining_qty',
        'unit_cost','currency','received_at'
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'received_qty' => 'decimal:3',
        'remaining_qty' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
