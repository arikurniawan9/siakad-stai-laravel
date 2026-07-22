<?php

namespace App\Http\Controllers;

use App\Domain\Academic\ExamScheduleService;
use App\Http\Requests\AcademicCalendarEventRequest;
use App\Http\Requests\ExamScheduleRequest;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\ExamSchedule;
use App\Models\Room;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AcademicCalendarController extends Controller
{
    public function index(Request $request, ExamScheduleService $examService): Response
    {
        abort_unless($request->user()->can('calendar.view') || $request->user()->can('exams.view'), 403);
        $filters = $request->validate(['academic_term_id' => ['nullable', 'integer', 'exists:academic_terms,id'], 'month' => ['nullable', 'date_format:Y-m']]);
        $user = $request->user(); $manager = in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true);
        $termId = $filters['academic_term_id'] ?? null; $month = $filters['month'] ?? null;
        $eventQuery = AcademicCalendarEvent::query()->with('academicTerm:id,code,name')->when($termId, fn (Builder $query) => $query->where('academic_term_id', $termId))->when(! $manager, fn (Builder $query) => $query->where('is_published', true))->when($month, fn (Builder $query) => $query->whereBetween('starts_at', [$month.'-01 00:00:00', date('Y-m-t 23:59:59', strtotime($month.'-01'))]))->orderBy('starts_at');
        $examQuery = ExamSchedule::query()->with(['academicTerm:id,code,name', 'classGroup.course:id,code,name,credits', 'classGroup.lecturer:id,name,nidn', 'room:id,building_id,name,code,capacity', 'room.building:id,name'])->when($termId, fn (Builder $query) => $query->where('academic_term_id', $termId))->when(! $manager, fn (Builder $query) => $query->where('status', 'published'))->when($user->active_role === 'Dosen', fn (Builder $query) => $query->whereHas('classGroup', fn (Builder $class) => $class->where('lecturer_id', $user->lecturer?->id ?? 0)))->when($user->active_role === 'Mahasiswa', fn (Builder $query) => $query->whereHas('classGroup.enrollments', fn (Builder $enrollment) => $enrollment->where('status', 'enrolled')->whereHas('registration', fn (Builder $registration) => $registration->where('student_id', $user->student?->id ?? 0)->where('status', 'approved'))))->when($month, fn (Builder $query) => $query->whereBetween('exam_date', [$month.'-01', date('Y-m-t', strtotime($month.'-01'))]))->orderBy('exam_date')->orderBy('starts_at');
        $exams = $examQuery->get();
        if ($user->active_role === 'Mahasiswa' && $user->student) $exams->each(fn (ExamSchedule $exam) => $exam->setAttribute('eligibility', $examService->eligibility($exam, $user->student)));

        return Inertia::render('Academic/Calendar', [
            'filters' => ['academic_term_id' => (string) ($termId ?? ''), 'month' => $month ?? ''], 'events' => $eventQuery->get(), 'exams' => $exams,
            'termOptions' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'code', 'name', 'semester', 'starts_on', 'ends_on']),
            'classOptions' => $manager ? ClassGroup::query()->where('is_active', true)->with(['course:id,code,name,credits', 'academicTerm:id,code,name'])->orderByDesc('academic_term_id')->orderBy('name')->get(['id', 'academic_term_id', 'course_id', 'name']) : [],
            'roomOptions' => $manager ? Room::query()->where('is_active', true)->with('building:id,name')->orderBy('name')->get(['id', 'building_id', 'name', 'code', 'capacity']) : [],
            'abilities' => ['manageCalendar' => $manager && $user->can('calendar.create') && $user->can('calendar.update'), 'manageExams' => $manager && $user->can('exams.create') && $user->can('exams.update')], 'mode' => $user->active_role === 'Mahasiswa' ? 'student' : 'manager',
        ]);
    }

    public function storeEvent(AcademicCalendarEventRequest $request): RedirectResponse
    {
        Gate::authorize('create', AcademicCalendarEvent::class); $event = AcademicCalendarEvent::create([...$request->validated(), 'created_by' => $request->user()->id]); $this->audit($request, 'event_created', 'academic_calendar_event', $event->id, $event->only(['title', 'starts_at', 'ends_at']));
        return back()->with('success', 'Agenda kalender akademik berhasil dibuat.');
    }

    public function updateEvent(AcademicCalendarEventRequest $request, AcademicCalendarEvent $event): RedirectResponse
    {
        Gate::authorize('update', $event); $event->update([...$request->validated(), 'updated_by' => $request->user()->id]); $this->audit($request, 'event_updated', 'academic_calendar_event', $event->id, $event->only(['title', 'starts_at', 'ends_at']));
        return back()->with('success', 'Agenda kalender akademik berhasil diperbarui.');
    }

    public function destroyEvent(Request $request, AcademicCalendarEvent $event): RedirectResponse
    {
        Gate::authorize('delete', $event); $event->delete(); $this->audit($request, 'event_deleted', 'academic_calendar_event', $event->id, []);
        return back()->with('success', 'Agenda kalender akademik berhasil dihapus.');
    }

    public function storeExam(ExamScheduleRequest $request, ExamScheduleService $service): RedirectResponse
    {
        Gate::authorize('create', ExamSchedule::class); $exam = $service->create($request->validated(), $request->user()); $this->audit($request, 'exam_created', 'exam_schedule', $exam->id, $exam->only(['class_group_id', 'exam_type', 'exam_date']));
        return back()->with('success', 'Jadwal ujian berhasil dibuat.');
    }

    public function updateExam(ExamScheduleRequest $request, ExamSchedule $exam, ExamScheduleService $service): RedirectResponse
    {
        Gate::authorize('update', $exam); $exam = $service->update($exam, $request->validated(), $request->user()); $this->audit($request, 'exam_updated', 'exam_schedule', $exam->id, $exam->only(['class_group_id', 'exam_type', 'exam_date']));
        return back()->with('success', 'Jadwal ujian berhasil diperbarui.');
    }

    public function destroyExam(Request $request, ExamSchedule $exam): RedirectResponse
    {
        Gate::authorize('delete', $exam); if ($exam->status === 'published') throw ValidationException::withMessages(['exam' => 'Ujian yang sudah dipublikasikan tidak dapat dihapus; ubah statusnya menjadi dibatalkan.']); $exam->delete(); $this->audit($request, 'exam_deleted', 'exam_schedule', $exam->id, []);
        return back()->with('success', 'Jadwal ujian berhasil dihapus.');
    }

    public function card(Request $request, ExamSchedule $exam, ExamScheduleService $service)
    {
        Gate::authorize('view', $exam); abort_unless($request->user()->active_role === 'Mahasiswa' && $request->user()->student, 403); $exam->load(['academicTerm:id,code,name', 'classGroup.course:id,code,name,credits', 'classGroup.lecturer:id,name', 'room.building:id,name']); $student = $request->user()->student->load('user:id,name');
        if (! $service->eligibility($exam, $student)['eligible']) throw ValidationException::withMessages(['exam' => 'Kartu peserta hanya dapat diterbitkan setelah syarat KRS, presensi, dan keuangan terpenuhi.']);
        $verificationUrl = route('exams.verify', $exam->verification_code); $svg = (new Writer(new ImageRenderer(new RendererStyle(126, 1), new SvgImageBackEnd())))->writeString($verificationUrl); $options = new Options(); $options->set('isRemoteEnabled', false); $options->set('isHtml5ParserEnabled', true); $options->set('defaultFont', 'DejaVu Sans'); $dompdf = new Dompdf($options); $dompdf->loadHtml(view('exams.card', compact('exam', 'student', 'verificationUrl') + ['qrCode' => 'data:image/svg+xml;base64,'.base64_encode($svg)])->render()); $dompdf->setPaper('A4', 'portrait'); $dompdf->render();
        return response($dompdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="kartu-ujian-'.$exam->exam_type.'-'.$exam->id.'.pdf"']);
    }

    public function verify(string $verificationCode)
    {
        $exam = ExamSchedule::query()->with(['academicTerm:id,code,name', 'classGroup.course:id,code,name', 'room.building:id,name', 'room:id,building_id,name,code'])->where('verification_code', $verificationCode)->firstOrFail();
        return view('exams.verify', ['exam' => $exam, 'valid' => $exam->status === 'published']);
    }

    private function audit(Request $request, string $action, string $type, int $id, array $data): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'academic_calendar', 'action' => $action, 'record_type' => $type, 'record_id' => (string) $id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
