<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InventoryItem extends Model
{
    use HasUuids;

    protected $table = 'inventory_items';

    protected $fillable = [
        'id',
        'branch_id',
        'item_name',
        'unit',
        'on_hand',
    ];

    protected $casts = [
        'on_hand' => 'decimal:3',
    ];

    public function lots()
    {
        return $this->hasMany(InventoryLot::class, 'inventory_item_id');
    }
}
