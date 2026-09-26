<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\PrintArea;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Catalog extends Component
{
    use WithPagination;

    public string $search = '';

    public string $categoryFilter = '';

    public string $statusFilter = '';

    #[Locked]
    public ?int $productId = null;

    public bool $showEditor = false;

    public bool $showNewCategory = false;

    public bool $newCategoryVisible = true;

    public string $newCategoryName = '';

    public array $form = [];

    public array $groups = [];

    public function mount()
    {
        Gate::authorize('catalog.manage');
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter()
    {
        $this->resetPage();
    }

    public function updatedStatusFilter()
    {
        $this->resetPage();
    }

    public function create()
    {
        Gate::authorize('catalog.manage');
        $this->productId = null;
        $this->form = ['name' => '', 'price' => 0, 'category_id' => $this->categoryFilter ?: Category::value('id'), 'print_area_id' => PrintArea::where('name', 'Cocina')->value('id'), 'type' => 'simple', 'description' => '', 'hourly_limit' => 0, 'daily_limit' => 0, 'is_active' => true];
        $this->groups = [];
        $this->showEditor = true;
        $this->resetValidation();
    }

    public function edit(int $id)
    {
        Gate::authorize('catalog.manage');
        $p = Product::with('modifierGroups.options')->findOrFail($id);
        $this->productId = $p->id;
        $this->form = $p->only(['name', 'price', 'category_id', 'print_area_id', 'type', 'description', 'hourly_limit', 'daily_limit', 'is_active']);
        $this->groups = $p->modifierGroups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'max_choices' => $g->max_choices, 'required' => $g->required, 'category_id' => $g->options->first()?->category_id ?? '', 'option_ids' => $g->options->pluck('id')->map(fn ($id) => (string) $id)->all()])->all();
        $this->showEditor = true;
        $this->resetValidation();
    }

    public function cancel()
    {
        $this->showEditor = false;
        $this->showNewCategory = false;
        $this->resetValidation();
    }

    public function addGroup()
    {
        Gate::authorize('catalog.manage');
        $this->groups[] = ['id' => null, 'name' => '', 'max_choices' => 1, 'required' => false, 'category_id' => '', 'option_ids' => []];
    }

    public function removeGroup(int $i)
    {
        unset($this->groups[$i]);
        $this->groups = array_values($this->groups);
    }

    public function createCategory()
    {
        Gate::authorize('catalog.manage');
        $this->validate(['newCategoryName' => 'required|string|max:100|unique:categories,name', 'newCategoryVisible' => 'boolean']);
        $c = Category::create(['name' => $this->newCategoryName, 'visible_in_pos' => $this->newCategoryVisible]);
        $this->form['category_id'] = $c->id;
        $this->newCategoryName = '';
        $this->newCategoryVisible = true;
        $this->showNewCategory = false;
        $this->resetValidation();
    }

    public function save()
    {
        Gate::authorize('catalog.manage');
        $this->validate(['form.name' => 'required|string|max:150', 'form.price' => 'required|numeric|decimal:0,2|min:0|max:999999', 'form.category_id' => 'required|exists:categories,id', 'form.print_area_id' => 'required|exists:print_areas,id', 'form.type' => 'required|in:simple,compuesto', 'form.description' => 'nullable|string|max:3000', 'form.hourly_limit' => 'required|integer|min:0|max:100000', 'form.daily_limit' => 'required|integer|min:0|max:100000', 'form.is_active' => 'boolean']);
        if ($this->form['type'] === 'compuesto') {
            $this->validate(['groups' => 'required|array|min:1|max:20', 'groups.*.name' => 'required|string|max:100', 'groups.*.max_choices' => 'required|integer|min:1|max:100', 'groups.*.required' => 'boolean', 'groups.*.option_ids' => 'required|array|min:1|max:100', 'groups.*.option_ids.*' => 'required|integer|exists:products,id']);
        }
        DB::transaction(function () {
            $p = $this->productId ? Product::lockForUpdate()->findOrFail($this->productId) : new Product;
            $p->fill(collect($this->form)->only(['name', 'price', 'category_id', 'print_area_id', 'type', 'description', 'hourly_limit', 'daily_limit', 'is_active'])->all());
            $p->max_choices = 0;
            $p->save();
            $keep = [];
            if ($p->type === 'compuesto') {
                foreach ($this->groups as $group) {
                    $ids = array_unique(array_map('intval', $group['option_ids']));
                    if (in_array($p->id, $ids) || Product::whereIn('id', $ids)->where('type', 'simple')->where('is_active', true)->count() !== count($ids)) {
                        throw ValidationException::withMessages(['groups' => 'Las opciones deben ser productos simples activos, distintos del producto principal.']);
                    }
                    $g = ! empty($group['id']) ? $p->modifierGroups()->findOrFail($group['id']) : $p->modifierGroups()->make();
                    $g->fill(['name' => $group['name'], 'max_choices' => $group['max_choices'], 'required' => $group['required']])->save();
                    $g->options()->sync($ids);
                    $keep[] = $g->id;
                }
            }
            $p->modifierGroups()->whereNotIn('id', $keep)->delete();
            // Legacy selections are replaced by groups; historical order snapshots are independent.
            $p->options()->delete();
        }, 3);
        $this->showEditor = false;
        $this->resetValidation();
        session()->flash('success', 'Producto y grupos guardados.');
    }

    public function render()
    {
        Gate::authorize('catalog.manage');

        return view('livewire.catalog', ['products' => Product::with('category', 'printArea')->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))->when($this->categoryFilter, fn ($q) => $q->where('category_id', $this->categoryFilter))->when($this->statusFilter !== '', fn ($q) => $q->where('is_active', $this->statusFilter === 'active'))->orderBy('name')->paginate(20), 'categories' => Category::orderBy('name')->get(), 'areas' => PrintArea::all(), 'auxiliaries' => Product::with('category')->where('is_active', true)->where('type', 'simple')->when($this->productId, fn ($q) => $q->where('id', '!=', $this->productId))->orderBy('name')->get()])->layout('components.layouts.app',['title' => 'Catálogo de productos']);
    }
}
