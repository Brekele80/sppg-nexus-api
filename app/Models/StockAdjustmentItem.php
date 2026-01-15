<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class StockAdjustmentItem extends Model
{
    use HasUuids;

    protected $table = 'stock_adjustment_items';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','stock_adjustment_id','line_no',
        'inventory_item_id','item_name','unit',
        'direction','qty',
        'expiry_date','unit_cost','currency','received_at',
        'preferred_lot_id','remarks',
    ];

    protected $casts = [
        'qty'        => 'decimal:3',
        'unit_cost'  => 'decimal:2',
        'expiry_date'=> 'date',
        'received_at'=> 'datetime',
    ];

    public function adjustment()
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }
}
