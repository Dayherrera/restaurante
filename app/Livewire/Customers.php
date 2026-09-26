<?php

namespace App\Livewire;

use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Customers extends Component
{
    use WithPagination;

    public string $search = '';

    public array $customerForm = [];

    public bool $showEditor = false;

    #[Locked]
    public ?int $customerId = null;

    public function mount()
    {
        Gate::authorize('pos.sell');
        $this->customerForm = CustomerService::blank();
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function create()
    {
        $this->customerId = null;
        $this->customerForm = CustomerService::blank();
        $this->showEditor = true;
        $this->resetValidation();
    }

    public function edit(int $id)
    {
        Gate::authorize('pos.sell');
        $c = Customer::findOrFail($id);
        $this->customerId = $id;
        $this->customerForm = $c->only(array_keys(CustomerService::blank()));
        $this->showEditor = true;
        $this->resetValidation();
    }

    public function save()
    {
        app(CustomerService::class)->save($this->customerForm, $this->customerId);
        $this->showEditor = false;
        $this->resetValidation();
        session()->flash('success', 'Cliente guardado. Los pedidos anteriores conservan sus datos originales.');
    }

    public function render()
    {
        Gate::authorize('pos.sell');
        $phone = CustomerService::phone($this->search);

        return view('livewire.customers', ['customers' => Customer::when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->when($phone !== '', fn ($q) => $q->orWhere('phone', 'like', '%'.$phone.'%'))))->orderBy('name')->paginate(20)])->layout('components.layouts.app', ['title' => 'Clientes']);
    }
}
