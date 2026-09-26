<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\PrintArea;
use App\Models\Product;
use Illuminate\Database\Seeder;

class RealMenuSeeder extends Seeder
{
    public function run(): void
    {
        $area = PrintArea::firstOrCreate(['name' => 'Cocina']);
        $rows = json_decode(file_get_contents(database_path('data/menu-real.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            $category = Category::firstOrCreate(['name' => $row['category']]);
            $product = Product::firstOrCreate(['name' => $row['name']], ['category_id' => $category->id, 'print_area_id' => $area->id, 'price' => $row['price'], 'type' => $row['type'], 'max_choices' => $row['max_choices'], 'description' => $row['description'], 'hourly_limit' => 0, 'daily_limit' => 0, 'is_active' => true]);
            if ($product->wasRecentlyCreated) {
                foreach ($row['options'] as $option) {
                    $product->options()->create(['option_name' => $option]);
                }
            }
        }
    }
}
