<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InventoryMovement extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'branch_id',
        'inventory_item_id',
        'direction',     // IN / OUT
        'qty',
        'unit',
        'source_type',   // GR, ISSUE, ADJUSTMENT, etc.
        'source_id',
        'notes',
        'actor_id',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];
}
