<?php

namespace App\Domain\MasterData;

use App\Models\AcademicTerm;
use App\Models\Building;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Room;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class MasterDataTransferService
{
    public const MAX_ROWS = 500;

    private const DEFINITIONS = [
        'campuses' => ['model' => Campus::class, 'headers' => ['code', 'name', 'address', 'is_active']],
        'faculties' => ['model' => Faculty::class, 'headers' => ['code', 'name', 'campus_code']],
        'programs' => ['model' => Program::class, 'headers' => ['code', 'name', 'degree', 'faculty_code', 'is_active']],
        'academic-terms' => ['model' => AcademicTerm::class, 'headers' => ['code', 'name', 'semester', 'starts_on', 'ends_on', 'is_active']],
        'courses' => ['model' => Course::class, 'headers' => ['code', 'name', 'credits', 'type', 'program_code', 'is_active']],
        'buildings' => ['model' => Building::class, 'headers' => ['campus_code', 'code', 'name', 'floor_count', 'description', 'is_active']],
        'rooms' => ['model' => Room::class, 'headers' => ['campus_code', 'building_code', 'code', 'name', 'floor', 'type', 'capacity', 'facilities', 'is_active']],
        'lecturers' => ['model' => Lecturer::class, 'headers' => ['nidn', 'name', 'program_code', 'user_email', 'employee_number', 'academic_title', 'employment_status', 'expertise', 'is_active']],
        'students' => ['model' => Student::class, 'headers' => ['nim', 'user_email', 'program_code', 'advisor_nidn', 'admission_term_code', 'cohort_year', 'registration_type', 'gender', 'birth_date', 'phone', 'address', 'current_semester', 'status']],
    ];

    public function supports(string $resource): bool
    {
        return isset(self::DEFINITIONS[$resource]);
    }

    public function headers(string $resource): array
    {
        return $this->definition($resource)['headers'];
    }

    public function preview(UploadedFile $file, string $resource): array
    {
        $rows = $this->readCsv($file, $resource);
        $seen = [];
        $previewRows = [];
        $activeTerms = 0;

        foreach ($rows as $row) {
            $validated = $this->validateRow($resource, $row['values']);
            $identity = $this->identityKey($resource, $validated['data']);
            $duplicate = $identity !== '' && isset($seen[$identity]);
            if ($identity !== '') $seen[$identity] = true;
            $errors = $validated['errors'];
            if ($duplicate) $errors['code'][] = 'Kode duplikat ditemukan di dalam file.';
            if ($resource === 'academic-terms' && ($validated['data']['is_active'] ?? false) === true) $activeTerms++;

            $previewRows[] = [
                'line' => $row['line'],
                'values' => $row['values'],
                'data' => $validated['data'],
                'action' => $validated['action'],
                'errors' => $errors,
            ];
        }

        if ($resource === 'academic-terms' && $activeTerms > 1) {
            foreach ($previewRows as &$row) {
                if (($row['data']['is_active'] ?? false) === true) $row['errors']['is_active'][] = 'File hanya boleh memiliki satu periode aktif.';
            }
            unset($row);
        }

        $errorCount = count(array_filter($previewRows, fn (array $row): bool => $row['errors'] !== []));

        return [
            'resource' => $resource,
            'file_name' => $file->getClientOriginalName(),
            'total_rows' => count($previewRows),
            'valid_rows' => count($previewRows) - $errorCount,
            'error_rows' => $errorCount,
            'rows' => $previewRows,
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

    public function commit(array $preview, ?User $actor = null): array
    {
        $fresh = $this->revalidate($preview);
        if ($fresh['error_rows'] > 0) {
            throw ValidationException::withMessages(['import' => 'Data berubah sejak preview. Unggah ulang file dan periksa laporan validasi terbaru.']);
        }

        $created = 0;
        $updated = 0;
        $codes = [];

        DB::transaction(function () use ($fresh, $actor, &$created, &$updated, &$codes): void {
            $resource = $fresh['resource'];
            $class = $this->definition($resource)['model'];

            foreach ($fresh['rows'] as $row) {
                $data = $row['data'];
                $code = $this->recordIdentifier($resource, $data);
                $model = $this->existingQuery($resource, $class::query(), $data)->lockForUpdate()->first();
                $wasCreated = ! $model;
                if ($model) {
                    $model->update($data);
                    $updated++;
                } else {
                    $model = $class::create($data);
                    $created++;
                }
                if ($resource === 'academic-terms' && $model->is_active) {
                    AcademicTerm::query()->whereKeyNot($model->getKey())->update(['is_active' => false]);
                }
                if ($resource === 'lecturers' && $model->user_id && ($user = $model->user)) {
                    $user->assignRole(Role::findOrCreate('Dosen', 'web'));
                    if (blank($user->active_role)) $user->update(['active_role' => 'Dosen']);
                }
                if ($resource === 'students' && $model->user_id && ($user = $model->user)) {
                    $user->assignRole(Role::findOrCreate('Mahasiswa', 'web'));
                    if (blank($user->active_role)) $user->update(['active_role' => 'Mahasiswa']);
                    if ($wasCreated) StudentStatusHistory::create([
                        'student_id' => $model->id,
                        'academic_term_id' => $model->admission_term_id,
                        'changed_by_user_id' => $actor?->id,
                        'from_status' => null,
                        'to_status' => 'Aktif',
                        'effective_on' => now()->toDateString(),
                        'reason' => 'Pembuatan mahasiswa melalui import CSV',
                    ]);
                }
                $codes[] = $code;
            }
        }, 3);

        return ['created' => $created, 'updated' => $updated, 'total' => $created + $updated, 'codes' => $codes];
    }

    public function writeTemplate($stream, string $resource): void
    {
        $this->writeBom($stream);
        fputcsv($stream, $this->headers($resource), ',', '"', '');
        fputcsv($stream, $this->example($resource), ',', '"', '');
    }

    public function writeExport($stream, string $resource): void
    {
        $this->writeBom($stream);
        fputcsv($stream, $this->headers($resource), ',', '"', '');
        $class = $this->definition($resource)['model'];

        $class::query()->orderBy('id')->chunkById(200, function ($models) use ($stream, $resource): void {
            foreach ($models as $model) fputcsv($stream, $this->exportRow($resource, $model), ',', '"', '');
        });
    }

    private function readCsv(UploadedFile $file, string $resource): array
    {
        $path = $file->getRealPath();
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') throw ValidationException::withMessages(['file' => 'File CSV kosong atau tidak dapat dibaca.']);
        if (! mb_check_encoding($contents, 'UTF-8')) throw ValidationException::withMessages(['file' => 'File CSV harus menggunakan encoding UTF-8.']);

        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $handle = fopen($path, 'rb');
        if ($handle === false) throw ValidationException::withMessages(['file' => 'File CSV tidak dapat dibuka.']);

        $header = fgetcsv($handle, 0, $delimiter, '"', '');
        if ($header === false) throw ValidationException::withMessages(['file' => 'Header CSV tidak ditemukan.']);
        $header = array_map(fn ($value): string => str_replace([' ', '-'], '_', strtolower(trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B"))), $header);
        $expected = $this->headers($resource);
        $missing = array_values(array_diff($expected, $header));
        $unexpected = array_values(array_diff($header, $expected));
        if ($missing !== [] || $unexpected !== []) {
            $parts = [];
            if ($missing !== []) $parts[] = 'kolom kurang: '.implode(', ', $missing);
            if ($unexpected !== []) $parts[] = 'kolom tidak dikenal: '.implode(', ', $unexpected);
            throw ValidationException::withMessages(['file' => 'Header CSV tidak sesuai ('.implode('; ', $parts).').']);
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if (count($values) === 1 && trim((string) $values[0]) === '') continue;
            if (count($values) !== count($header)) {
                $rows[] = ['line' => $line, 'values' => array_fill_keys($header, ''), 'column_error' => true];
            } else {
                $rows[] = ['line' => $line, 'values' => array_combine($header, array_map(fn ($value): string => trim((string) $value), $values))];
            }
            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAX_ROWS.' baris data per impor.']);
            }
        }
        fclose($handle);
        if ($rows === []) throw ValidationException::withMessages(['file' => 'CSV tidak memiliki baris data.']);
        foreach ($rows as &$row) {
            if ($row['column_error'] ?? false) $row['values']['__column_error'] = 'Jumlah kolom tidak sesuai header.';
            unset($row['column_error']);
        }
        unset($row);

        return $rows;
    }

    private function validateRow(string $resource, array $values): array
    {
        $data = $this->normalize($resource, $values);
        $validator = Validator::make($data, $this->rules($resource));
        $errors = $validator->errors()->toArray();
        if (isset($values['__column_error'])) $errors['file'][] = $values['__column_error'];

        $reference = match ($resource) {
            'faculties' => ['field' => 'campus_code', 'target' => 'campus_id', 'model' => Campus::class],
            'programs' => ['field' => 'faculty_code', 'target' => 'faculty_id', 'model' => Faculty::class],
            'courses' => ['field' => 'program_code', 'target' => 'program_id', 'model' => Program::class],
            'buildings' => ['field' => 'campus_code', 'target' => 'campus_id', 'model' => Campus::class],
            'lecturers' => ['field' => 'program_code', 'target' => 'program_id', 'model' => Program::class],
            default => null,
        };
        if ($reference) {
            $referenceCode = strtoupper(trim((string) ($values[$reference['field']] ?? '')));
            $data[$reference['target']] = null;
            if ($referenceCode !== '') {
                $referenceQuery = $reference['model']::query()->where('code', $referenceCode);
                if (in_array($resource, ['buildings', 'lecturers'], true)) $referenceQuery->where('is_active', true);
                $referenceId = $referenceQuery->value('id');
                if (! $referenceId) $errors[$reference['field']][] = "Kode referensi {$referenceCode} tidak ditemukan.";
                else $data[$reference['target']] = $referenceId;
            }
        }

        if ($resource === 'rooms') {
            $campusCode = strtoupper(trim((string) ($values['campus_code'] ?? '')));
            $buildingCode = strtoupper(trim((string) ($values['building_code'] ?? '')));
            $data['building_id'] = null;
            $building = Building::query()
                ->where('code', $buildingCode)
                ->where('is_active', true)
                ->whereHas('campus', fn ($query) => $query->where('code', $campusCode)->where('is_active', true))
                ->first();
            if (! $building) $errors['building_code'][] = "Gedung {$campusCode}/{$buildingCode} tidak ditemukan atau tidak aktif.";
            else {
                $data['building_id'] = $building->id;
                if (is_numeric($data['floor'] ?? null) && (int) $data['floor'] > $building->floor_count) $errors['floor'][] = "Lantai melebihi jumlah lantai gedung ({$building->floor_count}).";
            }
            $data['facilities'] = filled($data['facilities'] ?? null)
                ? array_values(array_unique(array_filter(array_map('trim', explode('|', (string) $data['facilities'])))))
                : null;
            if (count($data['facilities'] ?? []) > 30) $errors['facilities'][] = 'Maksimal 30 fasilitas per ruangan.';
            foreach ($data['facilities'] ?? [] as $facility) {
                if (mb_strlen($facility) > 100) $errors['facilities'][] = 'Setiap nama fasilitas maksimal 100 karakter.';
            }
            $normalizedFacilities = array_map('mb_strtolower', $data['facilities'] ?? []);
            if (count($normalizedFacilities) !== count(array_unique($normalizedFacilities))) $errors['facilities'][] = 'Nama fasilitas tidak boleh duplikat.';
        }

        if ($resource === 'lecturers') {
            $email = strtolower(trim((string) ($values['user_email'] ?? '')));
            $data['user_id'] = null;
            if ($email !== '') {
                $user = User::query()->where('email', $email)->where('is_active', true)->first();
                if (! $user) $errors['user_email'][] = "Akun aktif {$email} tidak ditemukan.";
                else $data['user_id'] = $user->id;
            }
        }

        if ($resource === 'students') {
            $email = strtolower(trim((string) ($values['user_email'] ?? '')));
            $programCode = strtoupper(trim((string) ($values['program_code'] ?? '')));
            $advisorNidn = strtoupper(trim((string) ($values['advisor_nidn'] ?? '')));
            $termCode = strtoupper(trim((string) ($values['admission_term_code'] ?? '')));
            $user = User::query()->where('email', $email)->where('is_active', true)->first();
            $program = Program::query()->where('code', $programCode)->where('is_active', true)->first();
            $data['user_id'] = $user?->id;
            $data['program_id'] = $program?->id;
            $data['academic_advisor_id'] = null;
            $data['admission_term_id'] = null;
            if (! $user) $errors['user_email'][] = "Akun aktif {$email} tidak ditemukan.";
            if (! $program) $errors['program_code'][] = "Program aktif {$programCode} tidak ditemukan.";
            if ($advisorNidn !== '') {
                $advisor = Lecturer::query()->where('nidn', $advisorNidn)->where('is_active', true)->first();
                if (! $advisor) $errors['advisor_nidn'][] = "Dosen wali {$advisorNidn} tidak ditemukan atau tidak aktif.";
                elseif ($program && $advisor->program_id !== $program->id) $errors['advisor_nidn'][] = 'Dosen wali harus berasal dari program studi mahasiswa.';
                else $data['academic_advisor_id'] = $advisor->id;
            }
            if ($termCode !== '') {
                $term = AcademicTerm::query()->where('code', $termCode)->first();
                if (! $term) $errors['admission_term_code'][] = "Periode masuk {$termCode} tidak ditemukan.";
                else $data['admission_term_id'] = $term->id;
            }
        }

        $class = $this->definition($resource)['model'];
        $identifierField = match ($resource) { 'lecturers' => 'nidn', 'students' => 'nim', default => 'code' };
        $existing = filled($data[$identifierField] ?? null) ? $this->existingQuery($resource, $class::withTrashed(), $data)->first() : null;
        if ($existing?->trashed()) $errors[$identifierField][] = 'Identitas sudah ada di arsip. Pulihkan data tersebut sebelum mengimpor.';
        if ($resource === 'buildings' && $existing && ! $existing->trashed()) {
            $highestRoomFloor = (int) $existing->rooms()->max('floor');
            if ($highestRoomFloor > (int) ($data['floor_count'] ?? 0)) $errors['floor_count'][] = "Jumlah lantai tidak boleh kurang dari lantai ruangan tertinggi ({$highestRoomFloor}).";
        }

        if ($resource === 'lecturers' && ($data['user_id'] ?? null)) {
            $usedBy = Lecturer::withTrashed()->where('user_id', $data['user_id'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();
            if ($usedBy) $errors['user_email'][] = 'Akun sudah terhubung ke dosen lain.';
        }
        if ($resource === 'lecturers' && ($data['employee_number'] ?? null)) {
            $usedNumber = Lecturer::withTrashed()->where('employee_number', $data['employee_number'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();
            if ($usedNumber) $errors['employee_number'][] = 'Nomor pegawai sudah digunakan dosen lain.';
        }
        if ($resource === 'students' && ($data['user_id'] ?? null)) {
            $usedBy = Student::withTrashed()->where('user_id', $data['user_id'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();
            if ($usedBy) $errors['user_email'][] = 'Akun sudah terhubung ke mahasiswa lain.';
        }
        if ($resource === 'students') {
            if (! $existing && ($data['status'] ?? null) !== 'Aktif') $errors['status'][] = 'Mahasiswa baru harus berstatus Aktif.';
            if ($existing && ($data['status'] ?? null) !== $existing->status) $errors['status'][] = 'Perubahan status harus dilakukan melalui workflow status mahasiswa.';
        }

        unset($data['campus_code'], $data['faculty_code'], $data['program_code'], $data['building_code'], $data['user_email'], $data['advisor_nidn'], $data['admission_term_code']);

        return ['data' => $data, 'action' => $existing && ! $existing->trashed() ? 'update' : 'create', 'errors' => $errors];
    }

    private function normalize(string $resource, array $values): array
    {
        $data = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $values);
        if (isset($data['code'])) $data['code'] = strtoupper((string) $data['code']);
        if (isset($data['nidn'])) $data['nidn'] = strtoupper((string) $data['nidn']);
        if (isset($data['nim'])) $data['nim'] = strtoupper((string) $data['nim']);
        if (isset($data['employee_number']) && $data['employee_number'] !== '') $data['employee_number'] = strtoupper((string) $data['employee_number']);
        if (isset($data['user_email'])) $data['user_email'] = strtolower((string) $data['user_email']);
        if (isset($data['advisor_nidn'])) $data['advisor_nidn'] = strtoupper((string) $data['advisor_nidn']);
        if (isset($data['admission_term_code'])) $data['admission_term_code'] = strtoupper((string) $data['admission_term_code']);
        if (array_key_exists('is_active', $data)) $data['is_active'] = $this->booleanValue($data['is_active']);
        foreach (['address', 'description', 'facilities', 'starts_on', 'ends_on', 'birth_date', 'gender', 'phone', 'campus_code', 'faculty_code', 'program_code', 'building_code', 'user_email', 'employee_number', 'academic_title', 'expertise', 'advisor_nidn', 'admission_term_code'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') $data[$field] = null;
        }
        if ($resource === 'academic-terms' && isset($data['semester'])) $data['semester'] = ucfirst(strtolower((string) $data['semester']));
        if ($resource === 'courses' && isset($data['type'])) $data['type'] = ucfirst(strtolower((string) $data['type']));

        return $data;
    }

    private function rules(string $resource): array
    {
        return match ($resource) {
            'campuses' => ['code' => ['required', 'string', 'max:30', 'alpha_dash'], 'name' => ['required', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:255'], 'is_active' => ['required', 'boolean']],
            'faculties' => ['code' => ['required', 'string', 'max:30', 'alpha_dash'], 'name' => ['required', 'string', 'max:120'], 'campus_code' => ['nullable', 'string', 'max:30']],
            'programs' => ['code' => ['required', 'string', 'max:30', 'alpha_dash'], 'name' => ['required', 'string', 'max:120'], 'degree' => ['required', 'string', 'max:20'], 'faculty_code' => ['nullable', 'string', 'max:30'], 'is_active' => ['required', 'boolean']],
            'academic-terms' => ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:120'], 'semester' => ['required', Rule::in(['Ganjil', 'Genap', 'Pendek'])], 'starts_on' => ['nullable', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'is_active' => ['required', 'boolean']],
            'courses' => ['code' => ['required', 'string', 'max:30', 'alpha_dash'], 'name' => ['required', 'string', 'max:160'], 'credits' => ['required', 'integer', 'min:1', 'max:12'], 'type' => ['required', Rule::in(['Wajib', 'Pilihan'])], 'program_code' => ['nullable', 'string', 'max:30'], 'is_active' => ['required', 'boolean']],
            'buildings' => ['campus_code' => ['required', 'string', 'max:30'], 'code' => ['required', 'string', 'max:30', 'alpha_dash'], 'name' => ['required', 'string', 'max:120'], 'floor_count' => ['required', 'integer', 'min:1', 'max:100'], 'description' => ['nullable', 'string', 'max:1000'], 'is_active' => ['required', 'boolean']],
            'rooms' => ['campus_code' => ['required', 'string', 'max:30'], 'building_code' => ['required', 'string', 'max:30'], 'code' => ['required', 'string', 'max:30', 'alpha_dash'], 'name' => ['required', 'string', 'max:120'], 'floor' => ['required', 'integer', 'min:1', 'max:100'], 'type' => ['required', Rule::in(['Kelas', 'Laboratorium', 'Aula', 'Kantor', 'Perpustakaan', 'Lainnya'])], 'capacity' => ['required', 'integer', 'min:1', 'max:10000'], 'facilities' => ['nullable', 'string', 'max:2000'], 'is_active' => ['required', 'boolean']],
            'lecturers' => ['nidn' => ['required', 'string', 'max:30', 'regex:/^[0-9A-Z.\/-]+$/'], 'name' => ['required', 'string', 'max:150'], 'program_code' => ['required', 'string', 'max:30'], 'user_email' => ['nullable', 'email:rfc', 'max:255'], 'employee_number' => ['nullable', 'string', 'max:50'], 'academic_title' => ['nullable', 'string', 'max:80'], 'employment_status' => ['required', Rule::in(['Tetap', 'Tidak Tetap'])], 'expertise' => ['nullable', 'string', 'max:160'], 'is_active' => ['required', 'boolean']],
            'students' => ['nim' => ['required', 'string', 'max:30', 'alpha_dash'], 'user_email' => ['required', 'email:rfc', 'max:255'], 'program_code' => ['required', 'string', 'max:30'], 'advisor_nidn' => ['nullable', 'string', 'max:30'], 'admission_term_code' => ['nullable', 'string', 'max:30'], 'cohort_year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year + 1)], 'registration_type' => ['required', Rule::in(['Reguler', 'Transfer', 'Pindahan'])], 'gender' => ['nullable', Rule::in(['L', 'P'])], 'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'], 'phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:1000'], 'current_semester' => ['required', 'integer', 'min:1', 'max:20'], 'status' => ['required', Rule::in(['Aktif', 'Cuti', 'Lulus', 'Nonaktif'])]],
        };
    }

    private function revalidate(array $preview): array
    {
        $rows = [];
        foreach ($preview['rows'] as $row) {
            $validated = $this->validateRow($preview['resource'], $row['values']);
            $rows[] = [...$row, ...$validated];
        }
        $errorCount = count(array_filter($rows, fn (array $row): bool => $row['errors'] !== []));

        return [...$preview, 'rows' => $rows, 'valid_rows' => count($rows) - $errorCount, 'error_rows' => $errorCount];
    }

    private function booleanValue(mixed $value): mixed
    {
        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'ya', 'yes', 'aktif'], true)) return true;
        if (in_array($normalized, ['0', 'false', 'tidak', 'no', 'nonaktif'], true)) return false;

        return $value;
    }

    private function exportRow(string $resource, object $model): array
    {
        return match ($resource) {
            'campuses' => [$model->code, $model->name, $model->address, $model->is_active ? 1 : 0],
            'faculties' => [$model->code, $model->name, $model->campus?->code],
            'programs' => [$model->code, $model->name, $model->degree, $model->faculty?->code, $model->is_active ? 1 : 0],
            'academic-terms' => [$model->code, $model->name, $model->semester, $model->starts_on?->format('Y-m-d'), $model->ends_on?->format('Y-m-d'), $model->is_active ? 1 : 0],
            'courses' => [$model->code, $model->name, $model->credits, $model->type, $model->program?->code, $model->is_active ? 1 : 0],
            'buildings' => [$model->campus?->code, $model->code, $model->name, $model->floor_count, $model->description, $model->is_active ? 1 : 0],
            'rooms' => [$model->building?->campus?->code, $model->building?->code, $model->code, $model->name, $model->floor, $model->type, $model->capacity, implode('|', $model->facilities ?? []), $model->is_active ? 1 : 0],
            'lecturers' => [$model->nidn, $model->name, $model->program?->code, $model->user?->email, $model->employee_number, $model->academic_title, $model->employment_status, $model->expertise, $model->is_active ? 1 : 0],
            'students' => [$model->nim, $model->user?->email, $model->program?->code, $model->academicAdvisor?->nidn, $model->admissionTerm?->code, $model->cohort_year, $model->registration_type, $model->gender, $model->birth_date?->format('Y-m-d'), $model->phone, $model->address, $model->current_semester, $model->status],
        };
    }

    private function example(string $resource): array
    {
        return match ($resource) {
            'campuses' => ['STAI-02', 'Kampus Cabang', 'Jl. Pendidikan No. 2', '1'],
            'faculties' => ['FEB', 'Fakultas Ekonomi dan Bisnis', 'STAI-01'],
            'programs' => ['MNJ-S1', 'Manajemen', 'S1', 'FEB', '1'],
            'academic-terms' => ['2027-GANJIL', 'Tahun Akademik 2027/2028', 'Ganjil', '2027-08-01', '2028-01-31', '0'],
            'courses' => ['MNJ101', 'Pengantar Manajemen', '3', 'Wajib', 'MNJ-S1', '1'],
            'buildings' => ['STAI-01', 'GDB', 'Gedung B', '3', 'Gedung perkuliahan', '1'],
            'rooms' => ['STAI-01', 'GDB', 'LAB-01', 'Laboratorium Komputer', '2', 'Laboratorium', '30', 'Proyektor|AC|Wi-Fi', '1'],
            'lecturers' => ['0123456789', 'Dr. Dosen Baru', 'TI-S1', 'dosen@example.ac.id', 'PEG-001', 'Lektor', 'Tetap', 'Rekayasa Perangkat Lunak', '1'],
            'students' => ['TI2027001', 'mahasiswa@example.ac.id', 'TI-S1', '0123456789', '2027-GANJIL', '2027', 'Reguler', 'L', '2008-01-15', '08123456789', 'Alamat mahasiswa', '1', 'Aktif'],
        };
    }

    private function identityKey(string $resource, array $data): string
    {
        $code = $this->recordIdentifier($resource, $data);
        if ($code === '') return '';

        return match ($resource) {
            'buildings' => ($data['campus_id'] ?? 'missing').'|'.$code,
            'rooms' => ($data['building_id'] ?? 'missing').'|'.$code,
            default => $code,
        };
    }

    private function existingQuery(string $resource, $query, array $data)
    {
        if ($resource === 'lecturers') return $query->where('nidn', $data['nidn']);
        if ($resource === 'students') return $query->where('nim', $data['nim']);
        $query->where('code', $data['code']);
        if ($resource === 'buildings') $query->where('campus_id', $data['campus_id'] ?? 0);
        if ($resource === 'rooms') $query->where('building_id', $data['building_id'] ?? 0);

        return $query;
    }

    private function recordIdentifier(string $resource, array $data): string
    {
        return (string) match ($resource) { 'lecturers' => $data['nidn'] ?? '', 'students' => $data['nim'] ?? '', default => $data['code'] ?? '' };
    }

    private function definition(string $resource): array
    {
        if (! $this->supports($resource)) abort(404);

        return self::DEFINITIONS[$resource];
    }

    private function writeBom($stream): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
    }
}
