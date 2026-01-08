<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RabLineItem extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','rab_version_id','item_name','unit','qty','unit_price','line_total'
    ];
}
