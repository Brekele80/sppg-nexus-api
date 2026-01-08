<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequest extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id','branch_id','requested_by','status','notes'];

    public function items()
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function rabs()
    {
        return $this->hasMany(RabVersion::class);
    }
}
