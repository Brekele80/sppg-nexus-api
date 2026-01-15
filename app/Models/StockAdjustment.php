<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class StockAdjustment extends Model
{
    use HasUuids;

    protected $table = 'stock_adjustments';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','company_id','branch_id','adjustment_no','status',
        'reason','notes',
        'created_by','submitted_by','approved_by','posted_by',
        'submitted_at','approved_at','posted_at',
        'voided_at','voided_by','void_reason',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
        'posted_at'    => 'datetime',
        'voided_at'    => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_id');
    }

    public function attachments()
    {
        return $this->hasMany(StockAdjustmentAttachment::class, 'stock_adjustment_id');
    }
}
