<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\User;

final class MenuService
{
    public function forUser(?User $user): array
    {
        if (! $user) return [];

        $menus = Menu::query()->with(['roles', 'children.roles'])->where('is_active', true)->orderBy('sort_order')->get();
        $visible = $menus->filter(fn (Menu $menu): bool => $this->visible($menu, $user));
        $byParent = $visible->groupBy('parent_id');

        return $this->build($byParent, null, $user);
    }

    private function build($byParent, ?int $parentId, User $user): array
    {
        return collect($byParent->get($parentId, []))->map(function (Menu $menu) use ($byParent, $user): array {
            $children = $this->build($byParent, $menu->id, $user);
            return [
                'key' => $menu->key,
                'label' => $menu->label,
                'href' => $menu->href,
                'icon' => $menu->icon,
                'children' => $children,
            ];
        })->values()->all();
    }

    private function visible(Menu $menu, User $user): bool
    {
        $roleVisible = $menu->roles->isEmpty() || $menu->roles->pluck('name')->intersect($user->getRoleNames())->isNotEmpty();
        return $roleVisible && (! $menu->permission || $user->can($menu->permission));
    }
}
