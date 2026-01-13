<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InventoryMovement extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'inventory_movements';

    protected $fillable = [
        'id',
        'branch_id',
        'inventory_item_id',

        // schema columns (per your migrations)
        'type',          // e.g. GR_IN, ISSUE_OUT, ADJUSTMENT
        'qty',           // signed: +IN, -OUT

        'inventory_lot_id',
        'source_type',   // GR, ISSUE, ADJUSTMENT
        'source_id',

        'ref_id',
        'ref_type',

        'actor_id',
        'note',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];
}
