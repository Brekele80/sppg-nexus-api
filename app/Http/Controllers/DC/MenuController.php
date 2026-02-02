<?php

namespace App\Http\Controllers\DC;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuRecipe;
use App\Models\RecipeIngredient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\AuthUser;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->attributes->get('auth_user');
        AuthUser::requireBranch($u);

        return Menu::where('company_id', $u->company_id)
            ->where('branch_id', $u->branch_id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function show(Request $request, string $id)
    {
        $u = $request->attributes->get('auth_user');
        AuthUser::requireBranch($u);

        return Menu::where('id', $id)
            ->where('company_id', $u->company_id)
            ->where('branch_id', $u->branch_id)
            ->with('recipes.ingredients')
            ->firstOrFail();
    }

    public function store(Request $request)
    {
        $u = $request->attributes->get('auth_user');
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);
        AuthUser::requireBranch($u);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'recipes' => ['required', 'array', 'min:1'],
            'recipes.*.name' => ['required', 'string'],
            'recipes.*.servings' => ['required', 'numeric', 'min:1'],
            'recipes.*.ingredients' => ['required', 'array', 'min:1'],
            'recipes.*.ingredients.*.inventory_item_id' => ['required', 'uuid'],
            'recipes.*.ingredients.*.qty_per_serving' => ['required', 'numeric', 'min:0.0001'],
            'recipes.*.ingredients.*.unit' => ['required', 'string']
        ]);

        return DB::transaction(function () use ($data, $u) {
            $menu = Menu::create([
                'id' => Str::uuid(),
                'company_id' => $u->company_id,
                'branch_id' => $u->branch_id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'version' => 1,
                'created_by' => $u->id
            ]);

            foreach ($data['recipes'] as $r) {
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
        $u = $request->attributes->get('auth_user');
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);
        AuthUser::requireBranch($u);

        $menu = Menu::where('id', $id)
            ->where('company_id', $u->company_id)
            ->where('branch_id', $u->branch_id)
            ->firstOrFail();

        $menu->update([
            'is_published' => true,
            'published_at' => now()
        ]);

        return $menu;
    }
}
