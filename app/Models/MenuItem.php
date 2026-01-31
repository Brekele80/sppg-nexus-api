<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuItem extends Model
{
  public $timestamps = false;
  protected $fillable = [
    'id','menu_id','name','category','servings'
  ];

  protected static function booted() {
    static::creating(function ($m) {
      $m->id = $m->id ?: Str::uuid();
    });
  }
}
