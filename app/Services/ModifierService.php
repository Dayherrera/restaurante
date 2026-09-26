<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class ModifierService
{
    public function snapshot(Product $product, array $selections): array
    {
        $groups = $product->modifierGroups()->with('options')->get()->keyBy('id');
        $result = [];
        foreach ($selections as $groupId => $options) {
            if (! $groups->has($groupId)) {
                throw ValidationException::withMessages(['modifiers' => 'Grupo de modificadores inválido.']);
            }
        }
        foreach ($groups as $group) {
            $selected = $selections[$group->id] ?? [];
            $choices = $group->options->keyBy('id');
            $count = 0;
            if (! is_array($selected)) {
                throw ValidationException::withMessages(['modifiers' => 'Selección inválida.']);
            }
            foreach ($selected as $id => $qty) {
                if (filter_var($qty, FILTER_VALIDATE_INT) === false || $qty < 0 || $qty > 100) {
                    throw ValidationException::withMessages(['modifiers' => 'Cantidad de modificadores inválida.']);
                }
                if (! $qty) {
                    continue;
                }
                if (! $choices->has($id) || ! $choices[$id]->is_active) {
                    throw ValidationException::withMessages(['modifiers' => 'Una opción seleccionada ya no está disponible.']);
                }
                $count += (int) $qty;
                $result[] = ['group' => $group->name, 'name' => $choices[$id]->name, 'quantity' => (int) $qty];
            }
            if ($count > $group->max_choices || ($group->required && $count != $group->max_choices)) {
                throw ValidationException::withMessages(['modifiers' => $group->name.': elige '.($group->required ? 'exactamente ' : 'hasta ').$group->max_choices.' opciones.']);
            }
        }
        if ($groups->isEmpty()) {
            throw ValidationException::withMessages(['modifiers' => 'Configura los grupos del producto compuesto antes de venderlo.']);
        }

        return $result;
    }
}
