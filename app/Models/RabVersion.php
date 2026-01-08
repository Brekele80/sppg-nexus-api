<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RabVersion extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','purchase_request_id','version_no','created_by','status','currency',
        'subtotal','tax','total','submitted_at','decided_at','decision_reason'
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function lineItems()
    {
        return $this->hasMany(RabLineItem::class);
    }
}
