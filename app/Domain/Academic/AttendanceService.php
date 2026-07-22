<?php

namespace App\Domain\Academic;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ClassGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AttendanceService
{
    public function createSession(ClassGroup $classGroup, array $data, User $actor): AttendanceSession
    {
        return DB::transaction(function () use ($classGroup, $data, $actor): AttendanceSession {
            if ($classGroup->attendanceSessions()->where('meeting_number', $data['meeting_number'])->exists()) throw ValidationException::withMessages(['meeting_number' => 'Nomor pertemuan sudah digunakan pada kelas ini.']);
            $session = $classGroup->attendanceSessions()->create([...$data, 'access_code' => filled($data['access_code'] ?? null) ? $data['access_code'] : (string) random_int(100000, 999999), 'created_by' => $actor->id]);
            $enrollmentIds = $classGroup->enrollments()->where('status', 'enrolled')->whereHas('registration', fn ($query) => $query->where('status', 'approved'))->pluck('id');
            $session->records()->createMany($enrollmentIds->map(fn ($id) => ['course_enrollment_id' => $id])->all());
            return $session;
        }, 3);
    }

    public function updateSession(AttendanceSession $session, array $data): AttendanceSession
    {
        if ($session->status === 'closed') throw ValidationException::withMessages(['session' => 'Pertemuan yang sudah ditutup tidak dapat diubah.']);
        if ($session->classGroup->attendanceSessions()->whereKeyNot($session->id)->where('meeting_number', $data['meeting_number'])->exists()) throw ValidationException::withMessages(['meeting_number' => 'Nomor pertemuan sudah digunakan.']);
        if (blank($data['access_code'] ?? null)) unset($data['access_code']); $session->update($data); return $session->fresh();
    }

    public function transition(AttendanceSession $session, string $status, User $actor): AttendanceSession
    {
        return DB::transaction(function () use ($session, $status, $actor): AttendanceSession {
            $session = AttendanceSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($status === 'open' && $session->status === 'draft') $session->update(['status' => 'open', 'opened_by' => $actor->id, 'opened_at' => now()]);
            elseif ($status === 'closed' && $session->status === 'open') { $session->records()->where('status', 'unmarked')->update(['status' => 'absent', 'recorded_by' => $actor->id, 'updated_at' => now()]); $session->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()]); }
            else throw ValidationException::withMessages(['status' => 'Transisi status pertemuan tidak valid.']);
            return $session->fresh();
        }, 3);
    }

    public function saveRecords(AttendanceSession $session, array $records, User $actor): void
    {
        if ($session->status === 'closed') throw ValidationException::withMessages(['session' => 'Presensi sudah ditutup dan dikunci.']);
        DB::transaction(function () use ($session, $records, $actor): void { foreach ($records as $row) { $record = $session->records()->lockForUpdate()->find($row['id']); if (! $record) throw ValidationException::withMessages(['records' => 'Terdapat data presensi di luar pertemuan ini.']); $record->update(['status' => $row['status'], 'notes' => $row['notes'] ?? null, 'recorded_by' => $actor->id, 'checked_in_at' => in_array($row['status'], ['present', 'late'], true) ? ($record->checked_in_at ?? now()) : null]); } }, 3);
    }

    public function selfCheckIn(AttendanceSession $session, int $enrollmentId, string $code, User $actor): AttendanceRecord
    {
        if ($session->status !== 'open') throw ValidationException::withMessages(['code' => 'Sesi presensi belum dibuka atau sudah ditutup.']);
        if (! hash_equals((string) $session->access_code, trim($code))) throw ValidationException::withMessages(['code' => 'Kode presensi tidak valid.']);
        if (now()->isBefore($session->starts_at->copy()->subMinutes(30)) || now()->isAfter($session->ends_at->copy()->addMinutes(30))) throw ValidationException::withMessages(['code' => 'Presensi hanya dapat dilakukan pada waktu pertemuan.']);
        $record = $session->records()->where('course_enrollment_id', $enrollmentId)->firstOrFail();
        if ($record->status !== 'unmarked') throw ValidationException::withMessages(['code' => 'Presensi Anda sudah tercatat.']);
        $record->update(['status' => now()->isAfter($session->starts_at->copy()->addMinutes(15)) ? 'late' : 'present', 'checked_in_at' => now(), 'recorded_by' => $actor->id]); return $record->fresh();
    }
}
