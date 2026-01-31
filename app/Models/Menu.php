<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Menu extends Model
{
  public $timestamps = false;
  protected $fillable = [
    'id','company_id','name','version','status','created_by'
  ];

  protected static function booted() {
    static::creating(function ($m) {
      $m->id = $m->id ?: Str::uuid();
    });
  }

  public function items() {
    return $this->hasMany(MenuItem::class);
  }
}
