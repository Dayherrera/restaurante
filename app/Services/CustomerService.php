<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public static function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            $digits = substr($digits, 3);
        }

        return $digits;
    }

    public static function blank(): array
    {
        return ['name' => '', 'phone' => '', 'street' => '', 'number' => '', 'neighborhood' => '', 'city' => 'COMITÁN DE DOMÍNGUEZ', 'state' => 'CHIAPAS', 'references' => ''];
    }

    public function save(array $data, ?int $id = null): Customer
    {
        Gate::authorize('pos.sell');
        $data['phone'] = self::phone((string) ($data['phone'] ?? ''));
        $data = Validator::make($data, ['name' => 'required|string|max:120', 'phone' => ['required', 'regex:/^[0-9]{7,15}$/', Rule::unique('customers', 'phone')->ignore($id)], 'street' => 'nullable|string|max:150', 'number' => 'nullable|string|max:30', 'neighborhood' => 'nullable|string|max:120', 'city' => 'required|string|max:120', 'state' => 'required|string|max:100', 'references' => 'nullable|string|max:500'], ['phone.unique' => 'Ese teléfono ya está registrado. Busca y selecciona al cliente para actualizar sus datos.', 'phone.regex' => 'Ingresa un teléfono de 7 a 15 dígitos.'])->validate();
        try {
            return DB::transaction(function () use ($data, $id) {
                $customer = $id ? Customer::lockForUpdate()->findOrFail($id) : new Customer;
                $customer->fill($data)->save();

                return $customer;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['phone' => 'El teléfono ya está registrado. Selecciona al cliente existente.']);
        }
    }

    public function requireAddress(Customer $customer): void
    {
        foreach (['street' => 'calle', 'number' => 'número', 'neighborhood' => 'colonia', 'city' => 'ciudad', 'state' => 'estado'] as $key => $label) {
            if (! filled($customer->$key)) {
                throw ValidationException::withMessages(['delivery' => 'Completa '.$label.' en los datos del cliente antes de elegir entrega a domicilio.']);
            }
        }
    }
}
