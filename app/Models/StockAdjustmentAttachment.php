<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class StockAdjustmentAttachment extends Model
{
    use HasUuids;

    protected $table = 'stock_adjustment_attachments';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id','stock_adjustment_id','company_id','uploaded_by',
        'file_name','mime_type','file_size','storage_key','public_url','created_at',
    ];
}
