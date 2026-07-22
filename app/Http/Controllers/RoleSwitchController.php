<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RoleSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', 'string', 'max:60']]);
        abort_unless($request->user()->hasRole($data['role']), 403);

        $request->user()->forceFill(['active_role' => $data['role']])->saveQuietly();
        $request->session()->put('active_role', $data['role']);

        return back()->with('success', 'Role aktif diubah ke '.$data['role'].'.');
    }
}
