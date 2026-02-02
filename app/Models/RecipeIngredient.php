<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecipeIngredient extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'recipe_id',
        'inventory_item_id',
        'qty_per_serving',
        'unit'
    ];

    protected static function booted() {
        static::creating(function ($model) {
            $model->id = $model->id ?? Str::uuid();
        });
    }
}
