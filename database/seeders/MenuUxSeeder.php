<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\PrintArea;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuUxSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $area = PrintArea::where('name', 'Cocina')->firstOrFail();
            $botanas = Category::firstOrCreate(['name' => 'Botanas para modificadores'], ['visible_in_pos' => false]);
            foreach (Product::where('type', 'compuesto')->whereHas('options')->with('options')->get() as $parent) {
                if ($parent->modifierGroups()->exists()) {
                    continue;
                }
                $group = $parent->modifierGroups()->create(['name' => 'Botanas a elegir', 'max_choices' => $parent->max_choices, 'required' => true]);
                $ids = [];
                foreach ($parent->options as $option) {
                    $extra = Product::where('name', 'Orden extra — '.$option->option_name)->first();
                    $aux = Product::firstOrCreate(['category_id' => $botanas->id, 'name' => $option->option_name], ['price' => $extra?->price ?? 0, 'type' => 'simple', 'print_area_id' => $area->id, 'description' => 'Opción incluida en el precio del producto principal.']);
                    $ids[] = $aux->id;
                }$group->options()->sync($ids);
            }
            $terms = Category::firstOrCreate(['name' => 'Términos de carne'], ['visible_in_pos' => false]);
            $garnishes = Category::firstOrCreate(['name' => 'Guarniciones para modificadores'], ['visible_in_pos' => false]);
            $cuts = Category::firstOrCreate(['name' => 'Cortes (pruebas)'], ['visible_in_pos' => true]);
            $rib = Product::firstOrCreate(['name' => 'Rib Eye (prueba)'], ['category_id' => $cuts->id, 'print_area_id' => $area->id, 'price' => 295, 'type' => 'compuesto', 'max_choices' => 0, 'description' => 'Producto de prueba: término obligatorio y hasta dos guarniciones opcionales. Precio de ejemplo.']);
            foreach ([[$terms, 'Término', 1, true, ['Término medio', 'Término 3/4', 'Bien cocido', 'Término azul']], [$garnishes, 'Guarniciones', 2, false, ['Papas a la francesa', 'Papas gajo', 'Ensalada de la casa', 'Arroz', 'Verduras al vapor']]] as [$category,$name,$max,$required,$names]) {
                $group = $rib->modifierGroups()->firstOrCreate(['name' => $name], ['max_choices' => $max, 'required' => $required]);
                $ids = [];
                foreach ($names as $option) {
                    $p = Product::firstOrCreate(['category_id' => $category->id, 'name' => $option], ['price' => 0, 'print_area_id' => $area->id, 'type' => 'simple', 'description' => 'Opción del Rib Eye de prueba, sin cargo adicional.']);
                    $ids[] = $p->id;
                }
                if ($group->wasRecentlyCreated) {
                    $group->options()->sync($ids);
                }
            }
        });
    }
}
