<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMenuRequest;
use App\Models\Menu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

final class MenuBuilderController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('menus.view'), 403);

        return Inertia::render('Admin/MenuBuilder', [
            'menus' => Menu::query()->with('roles:id,name')->orderBy('sort_order')->get(),
            'parents' => Menu::query()->whereNull('parent_id')->orderBy('sort_order')->get(['id', 'label']),
            'roles' => Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(StoreMenuRequest $request): RedirectResponse
    {
        $menu = Menu::create($request->safe()->except('roles'));
        $menu->roles()->sync($this->roleIds($request->input('roles', [])));
        return back()->with('success', 'Menu berhasil ditambahkan.');
    }

    public function update(StoreMenuRequest $request, Menu $menu): RedirectResponse
    {
        abort_if($request->integer('parent_id') === $menu->id, 422, 'Menu tidak boleh menjadi parent dirinya sendiri.');
        $menu->update($request->safe()->except('roles'));
        $menu->roles()->sync($this->roleIds($request->input('roles', [])));
        return back()->with('success', 'Menu berhasil diperbarui.');
    }

    public function destroy(Request $request, Menu $menu): RedirectResponse
    {
        abort_unless($request->user()->can('menus.delete'), 403);
        $menu->delete();
        return back()->with('success', 'Menu berhasil dihapus.');
    }

    private function roleIds(array $roles): array
    {
        return Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->pluck('id')->all();
    }
}
