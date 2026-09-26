<?php

namespace App\Livewire;

use App\Models\Category;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Categories extends Component
{
    use WithPagination;

    public string $search = '';

    public string $name = '';

    public bool $visible = true;

    #[Locked]
    public ?int $categoryId = null;

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function edit(int $id)
    {
        Gate::authorize('catalog.manage');
        $c = Category::findOrFail($id);
        $this->categoryId = $id;
        $this->name = $c->name;
        $this->visible = $c->visible_in_pos;
        $this->resetValidation();
    }

    public function clear()
    {
        $this->categoryId = null;
        $this->name = '';
        $this->visible = true;
        $this->resetValidation();
    }

    public function save()
    {
        Gate::authorize('catalog.manage');
        $this->validate(['name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($this->categoryId)], 'visible' => 'boolean']);
        $c = $this->categoryId ? Category::findOrFail($this->categoryId) : new Category;
        $c->fill(['name' => $this->name, 'visible_in_pos' => $this->visible])->save();
        $this->clear();
        session()->flash('success', 'Categoría guardada.');
    }

    public function render()
    {
        Gate::authorize('catalog.manage');

        return view('livewire.categories', ['categories' => Category::withCount('products')->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))->orderBy('name')->paginate(20)])->layout('components.layouts.app', ['title' => 'Categorías']);
    }
}
