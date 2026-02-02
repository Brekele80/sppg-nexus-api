<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MenuForecastService
{
    public static function forecast(string $menuId, int $days, int $mealsPerDay)
    {
        $sql = "
        SELECT
            ri.inventory_item_id,
            SUM(ri.qty_per_serving * ? * ? * mr.servings) AS required_qty,
            COALESCE(SUM(l.remaining_qty), 0) AS on_hand_qty,
            (SUM(ri.qty_per_serving * ? * ? * mr.servings)
              - COALESCE(SUM(l.remaining_qty), 0)) AS shortage_qty
        FROM menu_recipes mr
        JOIN recipe_ingredients ri ON ri.recipe_id = mr.id
        LEFT JOIN inventory_lots l
          ON l.inventory_item_id = ri.inventory_item_id
         AND l.remaining_qty > 0
        WHERE mr.menu_id = ?
        GROUP BY ri.inventory_item_id
        HAVING shortage_qty > 0
        ";

        return DB::select($sql, [
            $days,
            $mealsPerDay,
            $days,
            $mealsPerDay,
            $menuId
        ]);
    }
}
