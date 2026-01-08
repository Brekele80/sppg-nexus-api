<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequestItem extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id','purchase_request_id','item_name','unit','qty','remarks'];
}
