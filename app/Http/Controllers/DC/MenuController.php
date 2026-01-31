<?php

namespace App\Http\Controllers\DC;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuController extends Controller
{
  public function index(Request $r) {
    $cid = $r->attributes->get('company_id');

    return Menu::where('company_id', $cid)
      ->orderByDesc('created_at')
      ->get();
  }

  public function store(Request $r) {
    $cid = $r->attributes->get('company_id');
    $user = $r->user()->id;

    $r->validate([
      'name' => 'required|string',
      'items' => 'array|min:1'
    ]);

    return DB::transaction(function () use ($r, $cid, $user) {
      $latest = Menu::where('company_id', $cid)
        ->where('name', $r->name)
        ->max('version');

      $menu = Menu::create([
        'id' => Str::uuid(),
        'company_id' => $cid,
        'name' => $r->name,
        'version' => ($latest ?? 0) + 1,
        'status' => 'DRAFT',
        'created_by' => $user
      ]);

      foreach ($r->items as $item) {
        MenuItem::create([
          'menu_id' => $menu->id,
          'name' => $item['name'],
          'category' => $item['category'] ?? null,
          'servings' => $item['servings']
        ]);
      }

      return $menu->load('items');
    });
  }

  public function show(Request $r, $id) {
    $cid = $r->attributes->get('company_id');

    return Menu::where('company_id', $cid)
      ->where('id', $id)
      ->with('items')
      ->firstOrFail();
  }

  public function publish(Request $r, $id) {
    $cid = $r->attributes->get('company_id');

    $menu = Menu::where('company_id', $cid)
      ->where('id', $id)
      ->firstOrFail();

    $menu->status = 'PUBLISHED';
    $menu->save();

    return $menu;
  }
}
