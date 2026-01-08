<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class IssueRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id','ir_number','status',
        'created_by','approved_by','issued_by',
        'submitted_at','approved_at','issued_at',
        'notes','meta'
    ];

    protected $casts = [
        'meta' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(IssueRequestItem::class, 'issue_request_id');
    }
}
