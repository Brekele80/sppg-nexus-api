<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasUuids;

    protected $table = 'purchase_order_items';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'purchase_order_id',
        'item_name',
        'unit',
        'qty',
        'unit_price',
        'line_total',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id', 'id');
    }
}
