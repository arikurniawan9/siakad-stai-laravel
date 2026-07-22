<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', 'in:unread,read'], 'type' => ['nullable', 'string', 'max:60']]);
        $query = $request->user()->systemNotifications()->when(($filters['status'] ?? '') === 'unread', fn ($query) => $query->whereNull('read_at'))->when(($filters['status'] ?? '') === 'read', fn ($query) => $query->whereNotNull('read_at'))->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']));
        return Inertia::render('Notifications/Index', ['notifications' => $query->latest()->paginate(20)->withQueryString(), 'filters' => ['status' => $filters['status'] ?? '', 'type' => $filters['type'] ?? ''], 'types' => $request->user()->systemNotifications()->distinct()->pluck('type')]);
    }
    public function read(Request $request, SystemNotification $notification): RedirectResponse { abort_unless((int) $notification->user_id === (int) $request->user()->id, 403); $notification->update(['read_at' => $notification->read_at ?? now()]); return $notification->link ? redirect($notification->link) : back(); }
    public function readAll(Request $request): RedirectResponse { $request->user()->systemNotifications()->whereNull('read_at')->update(['read_at' => now()]); return back()->with('success', 'Semua notifikasi ditandai sudah dibaca.'); }
}
