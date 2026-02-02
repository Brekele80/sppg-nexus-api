<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuRecipe extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'menu_id',
        'name',
        'servings'
    ];

    protected static function booted() {
        static::creating(function ($model) {
            $model->id = $model->id ?? Str::uuid();
        });
    }

    public function ingredients() {
        return $this->hasMany(RecipeIngredient::class, 'recipe_id');
    }
}
