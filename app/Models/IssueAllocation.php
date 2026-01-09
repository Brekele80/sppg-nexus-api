<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class IssueAllocation extends Model
{
    use HasUuids;

    protected $table = 'issue_allocations';

    protected $fillable = [
        'id',
        'issue_request_item_id',
        'inventory_item_id',
        'qty',
        'unit_cost',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_cost' => 'decimal:2',
    ];

    public function item()
    {
        return $this->belongsTo(IssueRequestItem::class, 'issue_request_item_id');
    }
}
