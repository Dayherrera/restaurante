<?php

namespace App\Livewire;

use App\Models\CashShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryDriver;
use App\Models\Product;
use App\Services\CustomerService;
use App\Services\ModifierService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Pos extends Component
{
    #[Locked]
    public string $request_key = '';

    #[Locked]
    public string $stage = 'customer';

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public array $items = [];

    #[Locked]
    public string $scheduled_date = '';

    #[Locked]
    public string $scheduled_time = '';

    #[Locked]
    public ?int $modalProductId = null;

    #[Locked]
    public ?int $editingIndex = null;

    #[Locked]
    public array $modifiers = [];

    public array $customerForm = [];

    public string $customerSearch = '';

    public string $search = '';

    public string $category = '';

    public string $dateInput = '';

    public string $timeInput = '';

    public string $delivery_type = 'sucursal';

    public string $draftNotes = '';

    public $delivery_cost = 0;

    public $delivery_driver_id = '';

    public array $payments = ['efectivo' => 0, 'transferencia' => 0, 'tarjeta' => 0];

    public bool $override = false;

    public function mount()
    {
        Gate::authorize('pos.sell');
        $this->request_key = (string) Str::uuid();
        $this->customerForm = CustomerService::blank();
    }

    public function updatedCustomerSearch()
    {
        $this->customerId = null;
        $this->customerForm = CustomerService::blank();
        if (preg_match('/^[+\d\s().-]+$/', trim($this->customerSearch))) {
            $this->customerForm['phone'] = CustomerService::phone($this->customerSearch);
        }
    }

    public function selectCustomer(int $id)
    {
        Gate::authorize('pos.sell');
        $c = Customer::findOrFail($id);
        $this->customerId = $id;
        $this->customerForm = $c->only(array_keys(CustomerService::blank()));
        $this->resetValidation();
    }

    public function newCustomer()
    {
        $phone = $this->customerForm['phone'] ?? '';
        $this->customerId = null;
        $this->customerForm = CustomerService::blank();
        $this->customerForm['phone'] = $phone;
        $this->customerSearch = '';
        $this->resetValidation();
    }

    public function saveCustomer()
    {
        $c = app(CustomerService::class)->save($this->customerForm, $this->customerId);
        $this->customerId = $c->id;
        $this->customerForm = $c->only(array_keys(CustomerService::blank()));
        $this->stage = 'schedule';
        $this->dateInput = $this->scheduled_date;
        $this->timeInput = $this->scheduled_time;
        $this->resetValidation();
    }

    public function editCustomer()
    {
        Gate::authorize('pos.sell');
        $this->stage = 'customer';
        $this->modalProductId = null;
        $this->resetValidation();
    }

    public function updatedDateInput()
    {
        $this->override = false;
    }

    public function updatedTimeInput()
    {
        $this->override = false;
    }

    public function editSchedule()
    {
        $this->override = false;
        $this->stage = 'schedule';
        $this->dateInput = $this->scheduled_date;
        $this->timeInput = $this->scheduled_time;
        $this->modalProductId = null;
        $this->resetValidation();
    }

    private function requireCustomer(): Customer
    {
        Gate::authorize('pos.sell');
        if (! $this->customerId) {
            throw ValidationException::withMessages(['customer' => 'Primero registra al cliente.']);
        }

return Customer::findOrFail($this->customerId);
    }

    public function confirmSchedule()
    {
        $customer = $this->requireCustomer();
        $this->validate(['delivery_type' => 'required|in:sucursal,domicilio', 'delivery_cost' => 'required|numeric|min:0|max:999999']);
        if ($this->delivery_type === 'domicilio') {
            app(CustomerService::class)->requireAddress($customer);
        }
        app(OrderService::class)->previewCapacity($this->items, $this->dateInput, $this->timeInput, $this->override);
        $this->scheduled_date = $this->dateInput;
        $this->scheduled_time = $this->timeInput;
        $this->stage = 'pos';
        if ($this->delivery_type === 'sucursal') {
            $this->delivery_cost = 0;
            $this->delivery_driver_id = '';
        }
        $this->resetValidation();
    }

    private function ready(): void
    {
        $this->requireCustomer();
        if ($this->stage !== 'pos' || ! $this->scheduled_date || ! $this->scheduled_time) {
            throw ValidationException::withMessages(['schedule' => 'Confirma la fecha y hora de entrega antes de agregar productos.']);
        }
    }

    private function check(array $items): void
    {
        app(OrderService::class)->previewCapacity($items, $this->scheduled_date, $this->scheduled_time, $this->override);
    }

    public function add(int $id)
    {
        $this->ready();
        $p = Product::where('is_active', true)->whereHas('category', fn ($q) => $q->where('visible_in_pos', true))->findOrFail($id);
        $candidate = $this->items;
        $candidate[] = ['product_id' => $p->id, 'quantity' => 1, 'notes' => '', 'options' => [], 'modifiers' => []];
        $this->check($candidate);
        if ($p->type === 'compuesto') {
            $this->modalProductId = $id;
            $this->editingIndex = null;
            $this->modifiers = [];
            $this->draftNotes = '';
        } else {
            $this->items = $candidate;
        }
        $this->resetValidation();
    }

    public function quantity(int $index, int $delta)
    {
        $this->ready();
        if (! isset($this->items[$index]) || ! in_array($delta, [-1, 1])) {
            return;
        }$candidate = $this->items;
        $candidate[$index]['quantity'] += $delta;
        if ($candidate[$index]['quantity'] < 1) {
            return;
        }$this->check($candidate);
        $this->items = $candidate;
        $this->resetValidation();
    }

    public function remove(int $index)
    {
        $this->ready();
        unset($this->items[$index]);
        $this->items = array_values($this->items);
        $this->resetValidation();
    }

    public function editItem(int $index)
    {
        $this->ready();
        if (! isset($this->items[$index])) {
            return;
        }$item = $this->items[$index];
        $this->modalProductId = $item['product_id'];
        $this->editingIndex = $index;
        $this->modifiers = $item['modifiers'] ?? [];
        $this->draftNotes = $item['notes'] ?? '';
        $this->resetValidation();
    }

    public function option(int $groupId, int $optionId, int $delta)
    {
        $this->ready();
        if (! $this->modalProductId || ! in_array($delta, [-1, 1])) {
            return;
        }
        $group = Product::findOrFail($this->modalProductId)->modifierGroups()->findOrFail($groupId);
        $group->options()->where('is_active', true)->findOrFail($optionId);
        $options = $this->modifiers[$groupId] ?? [];
        if ($delta === 1 && $group->max_choices === 1) {
            $options = [$optionId => 1];
        } else {
            if ($delta === 1 && array_sum($options) >= $group->max_choices) {
                return;
            }$options[$optionId] = max(0, ($options[$optionId] ?? 0) + $delta);
        }
        $this->modifiers[$groupId] = $options;
        $this->resetValidation();
    }

    public function closeModifiers()
    {
        $this->modalProductId = null;
        $this->editingIndex = null;
        $this->modifiers = [];
        $this->draftNotes = '';
        $this->resetValidation();
    }

    public function confirmModifiers()
    {
        $this->ready();
        $p = Product::findOrFail($this->modalProductId);
        $this->validate(['draftNotes' => 'nullable|string|max:1000']);
        $snapshot = $p->type === 'compuesto' ? app(ModifierService::class)->snapshot($p, $this->modifiers) : [];
        $candidate = $this->items;
        $index = $this->editingIndex ?? count($candidate);
        $qty = $candidate[$index]['quantity'] ?? 1;
        $candidate[$index] = ['product_id' => $p->id, 'quantity' => $qty, 'notes' => $this->draftNotes, 'options' => [], 'modifiers' => $this->modifiers, 'summary' => $snapshot];
        $this->check($candidate);
        $this->items = $candidate;
        $this->closeModifiers();
    }

    public function save()
    {
        $this->ready();
        $c = $this->requireCustomer();
        if ($this->delivery_type === 'domicilio') {
            app(CustomerService::class)->requireAddress($c);
        }
        $order = app(OrderService::class)->create(['request_key' => $this->request_key, 'customer_id' => $c->id, 'customer_name' => $c->name, 'customer_phone' => $c->phone, 'delivery_type' => $this->delivery_type, 'delivery_address' => $c->address(), 'delivery_cost' => $this->delivery_cost, 'delivery_driver_id' => $this->delivery_driver_id ?: null, 'scheduled_date' => $this->scheduled_date, 'scheduled_time' => $this->scheduled_time, 'items' => $this->items, 'payments' => collect($this->payments)->map(fn ($amount, $method) => ['method' => $method, 'amount' => $amount ?: 0])->values()->all(), 'override' => $this->override]);
        session()->flash('success', 'Pedido registrado y comandas enviadas a impresión.');

        return redirect()->route('orders.show', $order);
    }

    public function render()
    {
        Gate::authorize('pos.sell');
        $phone = CustomerService::phone($this->customerSearch);
        $matches = mb_strlen(trim($this->customerSearch)) >= 2 ? Customer::where(fn ($q) => $q->where('name', 'like', '%'.$this->customerSearch.'%')->when($phone !== '', fn ($q) => $q->orWhere('phone', 'like', '%'.$phone.'%')))->orderBy('name')->limit(8)->get() : collect();
        $catalog = $this->stage === 'pos' ? Product::with('category')->where('is_active', true)->whereHas('category', fn ($q) => $q->where('visible_in_pos', true))->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))->when($this->category, fn ($q) => $q->where('category_id', $this->category))->orderBy('name')->get() : collect();
        $selected = Product::whereIn('id', array_column($this->items, 'product_id'))->get()->keyBy('id');
        $total = $this->delivery_type === 'domicilio' ? OrderService::cents($this->delivery_cost) : 0;
        foreach ($this->items as $i) {
            $total += OrderService::cents($selected->get($i['product_id'])?->price ?? 0) * $i['quantity'];
        }

        return view('livewire.pos', ['catalog' => $catalog, 'categories' => Category::where('visible_in_pos', true)->get(), 'selected' => $selected, 'matches' => $matches, 'customer' => $this->customerId ? Customer::find($this->customerId) : null, 'total' => $total / 100, 'modalProduct' => $this->modalProductId ? Product::with('modifierGroups.options')->find($this->modalProductId) : null, 'drivers' => DeliveryDriver::where('is_active', true)->get(), 'shift' => CashShift::where('user_id', auth()->id())->where('status','open')->first()])->layout('components.layouts.app',['title' => 'Nuevo pedido']);
    }
}
