<?php

namespace App\Domain\Security;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UserTransferService
{
    public const MAX_ROWS = 500;

    private const HEADERS = ['email', 'name', 'username', 'roles', 'active_role', 'is_active'];

    public function preview(UploadedFile $file, User $actor): array
    {
        $rows = $this->readCsv($file);

        return [
            ...$this->validateRows($rows, $actor),
            'file_name' => $file->getClientOriginalName(),
            'created_at' => now()->toIso8601String(),
        ];
    }

    public function present(array $preview, string $token): array
    {
        return [
            ...collect($preview)->except(['user_id'])->all(),
            'token' => $token,
            'rows' => collect($preview['rows'])->map(fn (array $row): array => collect($row)->except('data')->all())->all(),
        ];
    }

    public function commit(array $preview, User $actor): array
    {
        $rawRows = collect($preview['rows'])->map(fn (array $row): array => ['line' => $row['line'], 'values' => $row['values']])->all();
        $emails = collect($rawRows)->pluck('values.email')->map(fn ($email): string => strtolower(trim((string) $email)))->filter()->all();

        User::withTrashed()->whereIn('email', $emails)->lockForUpdate()->get();
        User::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('name', 'Admin'))->lockForUpdate()->get();

        $fresh = $this->validateRows($rawRows, $actor);
        if ($fresh['error_rows'] > 0) {
            throw ValidationException::withMessages(['import' => 'Data berubah sejak preview. Unggah ulang file dan periksa laporan validasi terbaru.']);
        }

        $updated = 0;
        foreach ($fresh['rows'] as $row) {
            $data = $row['data'];
            $user = User::query()->whereKey($data['id'])->lockForUpdate()->firstOrFail();
            $roles = $data['roles'];
            unset($data['id'], $data['roles']);
            $user->update($data);
            $user->syncRoles($roles);
            $updated++;
        }

        return ['created' => 0, 'updated' => $updated, 'total' => $updated];
    }

    public function writeTemplate($stream): void
    {
        $this->writeBom($stream);
        fputcsv($stream, self::HEADERS, ',', '"', '');
        fputcsv($stream, ['akun.tersedia@example.test', 'Nama Pengguna', 'nama-pengguna', 'Staff|Dosen', 'Staff', '1'], ',', '"', '');
    }

    public function writeExport($stream): void
    {
        $this->writeBom($stream);
        fputcsv($stream, self::HEADERS, ',', '"', '');
        User::query()->with('roles:id,name')->orderBy('id')->chunkById(200, function ($users) use ($stream): void {
            foreach ($users as $user) {
                fputcsv($stream, [
                    $user->email,
                    $user->name,
                    $user->username ?? '',
                    $user->roles->sortBy('name')->pluck('name')->implode('|'),
                    $user->active_role,
                    $user->is_active ? '1' : '0',
                ], ',', '"', '');
            }
        });
    }

    private function validateRows(array $rows, User $actor): array
    {
        $seenEmails = [];
        $seenUsernames = [];
        $previewRows = [];
        $activeAdminIds = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'Admin'))
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();

        foreach ($rows as $row) {
            $values = $row['values'];
            $roles = array_values(array_unique(array_filter(array_map('trim', explode('|', (string) ($values['roles'] ?? ''))))));
            $active = $this->booleanValue($values['is_active'] ?? null);
            $data = [
                'email' => strtolower(trim((string) ($values['email'] ?? ''))),
                'name' => trim((string) ($values['name'] ?? '')),
                'username' => filled($values['username'] ?? null) ? strtolower(trim((string) $values['username'])) : null,
                'roles' => $roles,
                'active_role' => trim((string) ($values['active_role'] ?? '')),
                'is_active' => $active,
            ];
            $validator = Validator::make($data, [
                'email' => ['required', 'email:rfc', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'username' => ['nullable', 'string', 'max:100', 'alpha_dash'],
                'roles' => ['required', 'array', 'min:1'],
                'roles.*' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
                'active_role' => ['required', 'string', Rule::in($roles)],
                'is_active' => ['required', 'boolean'],
            ]);
            $errors = $validator->errors()->toArray();
            if (isset($values['__column_error'])) $errors['file'][] = $values['__column_error'];

            $user = $data['email'] !== '' ? User::withTrashed()->where('email', $data['email'])->first() : null;
            if ($data['email'] !== '' && isset($seenEmails[$data['email']])) $errors['email'][] = 'Email duplikat ditemukan di dalam file.';
            if ($data['email'] !== '') $seenEmails[$data['email']] = true;
            if (! $user && $data['email'] !== '') $errors['email'][] = 'Akun belum tersedia. Buat akun melalui formulir agar kata sandi ditetapkan dengan aman.';
            if ($user?->trashed()) $errors['email'][] = 'Akun berada di arsip. Pulihkan akun sebelum melakukan sinkronisasi CSV.';

            if ($data['username']) {
                if (isset($seenUsernames[$data['username']])) $errors['username'][] = 'Username duplikat ditemukan di dalam file.';
                $seenUsernames[$data['username']] = true;
                $duplicate = User::withTrashed()->where('username', $data['username'])->when($user, fn ($query) => $query->whereKeyNot($user->id))->exists();
                if ($duplicate) $errors['username'][] = 'Username sudah digunakan akun lain.';
            }
            if ($user?->is($actor) && $active === false) $errors['is_active'][] = 'Akun yang sedang digunakan tidak dapat dinonaktifkan.';

            $rolesAreValid = ! collect($validator->errors()->keys())->contains(fn (string $key): bool => $key === 'roles' || str_starts_with($key, 'roles.'));
            if ($user && ! $user->trashed() && $active !== null && $rolesAreValid) {
                if ($active && in_array('Admin', $roles, true)) $activeAdminIds[$user->id] = true;
                else unset($activeAdminIds[$user->id]);
            }

            $previewRows[] = [
                'line' => $row['line'],
                'values' => collect($values)->except('__column_error')->all(),
                'data' => $user ? [...$data, 'id' => $user->id] : $data,
                'action' => 'update',
                'errors' => $errors,
                '_was_active_admin' => $user && $user->is_active && $user->hasRole('Admin'),
            ];
        }

        if ($activeAdminIds === []) {
            foreach ($previewRows as &$row) {
                if ($row['_was_active_admin']) $row['errors']['roles'][] = 'Admin aktif terakhir tidak dapat dinonaktifkan atau kehilangan role Admin.';
            }
            unset($row);
        }

        foreach ($previewRows as &$row) unset($row['_was_active_admin']);
        unset($row);
        $errorCount = count(array_filter($previewRows, fn (array $row): bool => $row['errors'] !== []));

        return [
            'total_rows' => count($previewRows),
            'valid_rows' => count($previewRows) - $errorCount,
            'error_rows' => $errorCount,
            'rows' => $previewRows,
        ];
    }

    private function readCsv(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') throw ValidationException::withMessages(['file' => 'File CSV kosong atau tidak dapat dibaca.']);
        if (! mb_check_encoding($contents, 'UTF-8')) throw ValidationException::withMessages(['file' => 'File CSV harus menggunakan encoding UTF-8.']);

        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $handle = fopen($path, 'rb');
        $header = $handle ? fgetcsv($handle, 0, $delimiter, '"', '') : false;
        if ($header === false) throw ValidationException::withMessages(['file' => 'Header CSV tidak ditemukan.']);
        $header = array_map(fn ($value): string => str_replace([' ', '-'], '_', strtolower(trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B"))), $header);
        $missing = array_values(array_diff(self::HEADERS, $header));
        $unexpected = array_values(array_diff($header, self::HEADERS));
        if ($missing !== [] || $unexpected !== []) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Header CSV tidak sesuai. Gunakan template pengguna terbaru.']);
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if (count($values) === 1 && trim((string) $values[0]) === '') continue;
            $rowValues = count($values) === count($header) ? array_combine($header, array_map(fn ($value): string => trim((string) $value), $values)) : array_fill_keys($header, '');
            if (count($values) !== count($header)) $rowValues['__column_error'] = 'Jumlah kolom tidak sesuai header.';
            $rows[] = ['line' => $line, 'values' => $rowValues];
            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAX_ROWS.' baris data per impor.']);
            }
        }
        fclose($handle);
        if ($rows === []) throw ValidationException::withMessages(['file' => 'CSV tidak memiliki baris data.']);

        return $rows;
    }

    private function booleanValue(mixed $value): ?bool
    {
        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'ya', 'yes', 'aktif' => true,
            '0', 'false', 'tidak', 'no', 'nonaktif' => false,
            default => null,
        };
    }

    private function writeBom($stream): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
    }
}
