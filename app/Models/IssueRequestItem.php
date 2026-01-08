<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class IssueRequestItem extends Model
{
    use HasUuids;

    protected $table = 'issue_request_items';

    protected $fillable = [
        'id','issue_request_id','inventory_item_id',
        'item_name','unit',
        'requested_qty','approved_qty','issued_qty','remarks'
    ];

    protected $casts = [
        'requested_qty' => 'decimal:3',
        'approved_qty' => 'decimal:3',
        'issued_qty' => 'decimal:3',
    ];

    public function allocations()
    {
        return $this->hasMany(IssueAllocation::class, 'issue_request_item_id');
    }
}
