<?php

namespace App\Http\Controllers\DC;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuRecipe;
use App\Models\RecipeIngredient;
use App\Services\MenuForecastService;
use App\Services\MenuAutoPrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        return Menu::where('company_id', $request->company_id)
            ->where('branch_id', $request->branch_id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function show(Request $request, string $id)
    {
        $menu = Menu::where('id', $id)
            ->where('company_id', $request->company_id)
            ->where('branch_id', $request->branch_id)
            ->with('recipes.ingredients')
            ->firstOrFail();

        return $menu;
    }

    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $menu = Menu::create([
                'id' => Str::uuid(),
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'name' => $request->name,
                'description' => $request->description,
                'version' => 1,
                'created_by' => $request->user()->id
            ]);

            foreach ($request->recipes as $r) {
                $recipe = MenuRecipe::create([
                    'id' => Str::uuid(),
                    'menu_id' => $menu->id,
                    'name' => $r['name'],
                    'servings' => $r['servings']
                ]);

                foreach ($r['ingredients'] as $i) {
                    RecipeIngredient::create([
                        'id' => Str::uuid(),
                        'recipe_id' => $recipe->id,
                        'inventory_item_id' => $i['inventory_item_id'],
                        'qty_per_serving' => $i['qty_per_serving'],
                        'unit' => $i['unit']
                    ]);
                }
            }

            return $menu->load('recipes.ingredients');
        });
    }

    public function publish(Request $request, string $id)
    {
        $menu = Menu::where('id', $id)
            ->where('company_id', $request->company_id)
            ->where('branch_id', $request->branch_id)
            ->firstOrFail();

        $menu->update([
            'is_published' => true,
            'published_at' => now()
        ]);

        return $menu;
    }

    public function forecast(Request $request, string $id)
    {
        return MenuForecastService::forecast(
            $id,
            $request->days,
            $request->meals_per_day
        );
    }

    public function generatePr(Request $request, string $id)
    {
        return MenuAutoPrService::generate(
            $request->company_id,
            $request->branch_id,
            $id,
            $request->days,
            $request->meals_per_day,
            $request->user()->id
        );
    }
}
