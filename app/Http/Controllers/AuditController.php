<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->active_role === 'Admin' && $request->user()->can('settings.view'), 403);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'module' => ['nullable', 'string', 'max:60'], 'action' => ['nullable', 'string', 'max:60'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        $query = $this->query($filters);
        $logs = $query->with('user:id,name,email')->latest()->paginate(30)->withQueryString();
        $logs->through(fn (AuditLog $log) => [...$log->toArray(), 'old_data' => $this->redact($log->old_data), 'new_data' => $this->redact($log->new_data)]);
        return Inertia::render('Admin/AuditLogs', ['logs' => $logs, 'filters' => ['q' => $filters['q'] ?? '', 'module' => $filters['module'] ?? '', 'action' => $filters['action'] ?? '', 'date_from' => $filters['date_from'] ?? '', 'date_to' => $filters['date_to'] ?? ''], 'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module'), 'summary' => ['today' => AuditLog::query()->whereDate('created_at', today())->count(), 'week' => AuditLog::query()->where('created_at', '>=', now()->subDays(7))->count(), 'users' => AuditLog::query()->whereNotNull('user_id')->distinct()->count('user_id'), 'modules' => AuditLog::query()->distinct()->count('module')]]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->active_role === 'Admin' && $request->user()->can('settings.view'), 403);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'module' => ['nullable', 'string', 'max:60'], 'action' => ['nullable', 'string', 'max:60'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        return response()->streamDownload(function () use ($filters): void { $stream = fopen('php://output', 'w'); fputcsv($stream, ['Waktu', 'Pengguna', 'Modul', 'Aksi', 'Tipe Data', 'ID Data', 'IP']); $this->query($filters)->with('user:id,name,email')->latest()->chunk(500, function ($logs) use ($stream): void { foreach ($logs as $log) fputcsv($stream, [$log->created_at?->toIso8601String(), $log->user?->email ?? 'system', $log->module, $log->action, $log->record_type, $log->record_id, $log->ip_address]); }); fclose($stream); }, 'audit-logs-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function query(array $filters): Builder
    {
        $search = trim((string) ($filters['q'] ?? ''));
        return AuditLog::query()->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('action', 'like', "%{$search}%")->orWhere('record_type', 'like', "%{$search}%")->orWhere('record_id', 'like', "%{$search}%")->orWhereHas('user', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))->when(isset($filters['module']), fn (Builder $query) => $query->where('module', $filters['module']))->when(isset($filters['action']), fn (Builder $query) => $query->where('action', 'like', '%'.$filters['action'].'%'))->when(isset($filters['date_from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['date_from']))->when(isset($filters['date_to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['date_to']));
    }

    private function redact(?array $data): ?array
    {
        if ($data === null) return null;
        $sensitive = ['password', 'password_confirmation', 'token', 'secret', 'api_key', 'signature', 'remember_token'];
        foreach ($data as $key => $value) { if (in_array(strtolower((string) $key), $sensitive, true)) $data[$key] = '[REDACTED]'; elseif (is_array($value)) $data[$key] = $this->redact($value); }
        return $data;
    }
}
