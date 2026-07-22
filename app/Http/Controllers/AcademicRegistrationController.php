<?php

namespace App\Http\Controllers;

use App\Domain\Academic\SemesterRegistrationService;
use App\Domain\Academic\CreditLimitService;
use App\Domain\Academic\CourseChangeService;
use App\Http\Requests\AcademicRegistrationPeriodRequest;
use App\Http\Requests\AddCourseEnrollmentRequest;
use App\Http\Requests\DecideRegistrationDispensationRequest;
use App\Http\Requests\RequestRegistrationDispensationRequest;
use App\Http\Requests\ReviewSemesterRegistrationRequest;
use App\Http\Requests\CourseChangeRequestRequest;
use App\Http\Requests\ReviewCourseChangeRequest;
use App\Models\AcademicRegistrationPeriod;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\CourseChangeRequest;
use App\Models\SemesterRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AcademicRegistrationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SemesterRegistration::class);
        $filters = $request->validate([
            'academic_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')],
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'approved', 'rejected'])],
            'selected' => ['nullable', 'integer', 'exists:semester_registrations,id'],
        ]);
        $user = $request->user();
        $student = $user->student()->with(['user:id,name,email', 'program:id,name,code', 'academicAdvisor:id,name,nidn'])->first();
        $periods = AcademicRegistrationPeriod::query()->with('academicTerm:id,name,code,semester,is_active')->latest('starts_at')->get();
        $activePeriod = isset($filters['academic_term_id'])
            ? $periods->firstWhere('academic_term_id', (int) $filters['academic_term_id'])
            : $periods->first(fn (AcademicRegistrationPeriod $period): bool => $period->is_open && now()->between($period->starts_at, $period->ends_at)) ?? $periods->first();
        $selectedTermId = isset($filters['academic_term_id']) ? (int) $filters['academic_term_id'] : $activePeriod?->academic_term_id;

        $ownRegistration = null;
        if ($student) {
            $ownRegistration = SemesterRegistration::query()
                ->with($this->detailRelations())
                ->where('student_id', $student->id)
                ->when($selectedTermId, fn (Builder $query) => $query->where('academic_term_id', $selectedTermId))
                ->latest('id')->first();
        }

        $reviewBase = SemesterRegistration::query()
            ->with(['student.user:id,name,email', 'student.program:id,name,code', 'student.academicAdvisor:id,name,nidn', 'academicTerm:id,name,code'])
            ->withCount(['enrollments', 'courseChangeRequests as pending_changes_count' => fn (Builder $query) => $query->where('status', 'requested')])
            ->when(isset($filters['academic_term_id']), fn (Builder $query) => $query->where('academic_term_id', $filters['academic_term_id']))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));

        if ($user->active_role === 'Dosen') {
            $lecturerId = $user->lecturer?->id;
            $reviewBase->whereHas('student', fn (Builder $query) => $query->where('academic_advisor_id', $lecturerId ?? 0));
        } elseif ($user->active_role === 'Keuangan') {
            $reviewBase->where('dispensation_status', 'requested');
        } elseif (! in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true)) {
            $reviewBase->whereRaw('1 = 0');
        }

        $canSeeQueue = $user->can('registration.update');
        $selected = null;
        if ($canSeeQueue) {
            $selectedId = isset($filters['selected']) ? (clone $reviewBase)->whereKey($filters['selected'])->value('id') : (clone $reviewBase)->orderByRaw("case when status = 'submitted' then 0 else 1 end")->latest('submitted_at')->value('id');
            $selected = $selectedId ? SemesterRegistration::query()->with($this->detailRelations())->find($selectedId) : null;
            if ($selected) Gate::authorize('view', $selected);
        }

        $editable = $ownRegistration && $user->can('update', $ownRegistration);
        $canRequestChange = $ownRegistration && $user->can('requestChange', $ownRegistration)
            && $ownRegistration->period?->is_changes_open && $ownRegistration->period?->changes_starts_at
            && $ownRegistration->period?->changes_ends_at && now()->between($ownRegistration->period->changes_starts_at, $ownRegistration->period->changes_ends_at);
        $availableClasses = collect();
        if ($editable || $canRequestChange) {
            $availableClasses = ClassGroup::query()
                ->with(['course:id,program_id,code,name,credits', 'lecturer:id,name,nidn', 'assignedRoom:id,name,code'])
                ->where('academic_term_id', $ownRegistration->academic_term_id)
                ->whereHas('course', fn (Builder $query) => $query->where('program_id', $student->program_id)->where('is_active', true))
                ->where('is_active', true)->orderBy('day')->orderBy('starts_at')->get();
        }

        $outstandingAmount = $ownRegistration
            ? (float) DB::table('billing_items')->where('student_id', $ownRegistration->student_id)->where('academic_term_id', $ownRegistration->academic_term_id)->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('amount - paid_amount'))
            : 0;
        $summaryBase = SemesterRegistration::query();
        if ($user->active_role === 'Mahasiswa') {
            $summaryBase->where('student_id', $student?->id ?? 0);
        } elseif ($user->active_role === 'Dosen') {
            $summaryBase->whereHas('student', fn (Builder $query) => $query->where('academic_advisor_id', $user->lecturer?->id ?? 0));
        } elseif ($user->active_role === 'Keuangan') {
            $summaryBase->where('dispensation_status', 'requested');
        }

        return Inertia::render('Academic/Registration', [
            'filters' => ['academic_term_id' => (string) ($filters['academic_term_id'] ?? ''), 'status' => $filters['status'] ?? '', 'selected' => $selected?->id],
            'termOptions' => AcademicTerm::query()->latest('starts_on')->get(['id', 'name', 'code', 'semester', 'is_active']),
            'periods' => $periods,
            'activePeriod' => $activePeriod,
            'student' => $student,
            'ownRegistration' => $ownRegistration,
            'availableClasses' => $availableClasses,
            'outstandingAmount' => $outstandingAmount,
            'registrations' => $canSeeQueue ? $reviewBase->latest('submitted_at')->paginate(10)->withQueryString() : null,
            'selectedRegistration' => $selected,
            'summary' => [
                'submitted' => (clone $summaryBase)->where('status', 'submitted')->count(),
                'approved' => (clone $summaryBase)->where('status', 'approved')->count(),
                'dispensations' => (clone $summaryBase)->where('dispensation_status', 'requested')->count(),
            ],
            'abilities' => [
                'start' => $student && $user->can('create', SemesterRegistration::class),
                'editOwn' => (bool) $editable,
                'submitOwn' => $ownRegistration ? $user->can('submit', $ownRegistration) : false,
                'requestChange' => (bool) $canRequestChange,
                'managePeriod' => $user->can('managePeriod', SemesterRegistration::class),
                'reviewSelected' => $selected ? $user->can('review', $selected) : false,
                'decideDispensation' => $selected ? $user->can('decideDispensation', $selected) : false,
            ],
        ]);
    }

    public function storePeriod(AcademicRegistrationPeriodRequest $request): RedirectResponse
    {
        $period = AcademicRegistrationPeriod::create($request->validated());
        $this->audit($request, 'period_created', 'academic_registration_period', $period->id, $period->getAttributes());

        return to_route('academic.registration', ['academic_term_id' => $period->academic_term_id])->with('success', 'Periode registrasi berhasil dibuat.');
    }

    public function updatePeriod(AcademicRegistrationPeriodRequest $request, AcademicRegistrationPeriod $period): RedirectResponse
    {
        $old = $period->getAttributes();
        $period->update($request->validated());
        $this->audit($request, 'period_updated', 'academic_registration_period', $period->id, ['old' => $old, 'new' => $period->getAttributes()]);

        return back()->with('success', 'Periode registrasi berhasil diperbarui.');
    }

    public function start(Request $request, SemesterRegistrationService $service, CreditLimitService $creditLimits): RedirectResponse
    {
        Gate::authorize('create', SemesterRegistration::class);
        $data = $request->validate(['period_id' => ['required', 'integer', 'exists:academic_registration_periods,id']]);
        $registration = $service->createDraft(AcademicRegistrationPeriod::findOrFail($data['period_id']), $request->user()->student()->firstOrFail(), $creditLimits);
        $this->audit($request, 'registration_started', 'semester_registration', $registration->id, ['academic_term_id' => $registration->academic_term_id]);

        return to_route('academic.registration', ['academic_term_id' => $registration->academic_term_id])->with('success', 'Registrasi semester dimulai. Silakan susun KRS Anda.');
    }

    public function addCourse(AddCourseEnrollmentRequest $request, SemesterRegistration $registration, SemesterRegistrationService $service): RedirectResponse
    {
        $enrollment = $service->addCourse($registration, ClassGroup::findOrFail($request->integer('class_group_id')));
        $this->audit($request, 'course_added', 'course_enrollment', $enrollment->id, ['registration_id' => $registration->id, 'class_group_id' => $enrollment->class_group_id]);

        return back()->with('success', 'Mata kuliah berhasil ditambahkan ke KRS.');
    }

    public function removeCourse(Request $request, SemesterRegistration $registration, CourseEnrollment $enrollment, SemesterRegistrationService $service): RedirectResponse
    {
        Gate::authorize('update', $registration);
        $service->removeCourse($registration, $enrollment);
        $this->audit($request, 'course_removed', 'course_enrollment', $enrollment->id, ['registration_id' => $registration->id]);

        return back()->with('success', 'Mata kuliah dihapus dari KRS.');
    }

    public function submit(Request $request, SemesterRegistration $registration, SemesterRegistrationService $service): RedirectResponse
    {
        Gate::authorize('submit', $registration);
        $service->submit($registration);
        $this->audit($request, 'registration_submitted', 'semester_registration', $registration->id, ['status' => 'submitted']);

        return back()->with('success', 'KRS berhasil diajukan kepada dosen pembimbing.');
    }

    public function requestDispensation(RequestRegistrationDispensationRequest $request, SemesterRegistration $registration, SemesterRegistrationService $service): RedirectResponse
    {
        $service->requestDispensation($registration, $request->validated('reason'));
        $this->audit($request, 'dispensation_requested', 'semester_registration', $registration->id, ['reason' => $request->validated('reason')]);

        return back()->with('success', 'Pengajuan dispensasi berhasil dikirim.');
    }

    public function decideDispensation(DecideRegistrationDispensationRequest $request, SemesterRegistration $registration, SemesterRegistrationService $service): RedirectResponse
    {
        $service->decideDispensation($registration, $request->validated('decision'), $request->validated('notes'), $request->user());
        $this->audit($request, 'dispensation_'.$request->validated('decision'), 'semester_registration', $registration->id, ['notes' => $request->validated('notes')]);

        return back()->with('success', 'Keputusan dispensasi berhasil disimpan.');
    }

    public function review(ReviewSemesterRegistrationRequest $request, SemesterRegistration $registration, SemesterRegistrationService $service): RedirectResponse
    {
        if ($request->validated('decision') === 'approved') {
            $service->approve($registration, $request->user(), $request->filled('max_credits') ? $request->integer('max_credits') : null);
        } else {
            $service->reject($registration, $request->user(), $request->validated('notes'));
        }
        $this->audit($request, 'registration_'.$request->validated('decision'), 'semester_registration', $registration->id, ['notes' => $request->validated('notes'), 'max_credits' => $request->validated('max_credits')]);

        return back()->with('success', $request->validated('decision') === 'approved' ? 'KRS berhasil disetujui.' : 'KRS dikembalikan untuk diperbaiki.');
    }

    public function requestChange(CourseChangeRequestRequest $request, SemesterRegistration $registration, CourseChangeService $service): RedirectResponse
    {
        $change = $service->request($registration, $request->validated());
        $this->audit($request, 'course_change_requested', 'course_change_request', $change->id, ['registration_id' => $registration->id, 'type' => $change->type, 'class_group_id' => $change->class_group_id]);
        return back()->with('success', 'Pengajuan perubahan KRS berhasil dikirim.');
    }

    public function cancelChange(Request $request, SemesterRegistration $registration, CourseChangeRequest $change, CourseChangeService $service): RedirectResponse
    {
        Gate::authorize('cancelChange', $registration);
        $service->cancel($registration, $change);
        $this->audit($request, 'course_change_cancelled', 'course_change_request', $change->id, ['registration_id' => $registration->id]);
        return back()->with('success', 'Pengajuan perubahan KRS dibatalkan.');
    }

    public function reviewChange(ReviewCourseChangeRequest $request, SemesterRegistration $registration, CourseChangeRequest $change, CourseChangeService $service): RedirectResponse
    {
        $service->review($registration, $change, $request->validated('decision'), $request->validated('notes'), $request->user());
        $this->audit($request, 'course_change_'.$request->validated('decision'), 'course_change_request', $change->id, ['registration_id' => $registration->id, 'notes' => $request->validated('notes')]);
        return back()->with('success', $request->validated('decision') === 'approved' ? 'Perubahan KRS disetujui.' : 'Perubahan KRS ditolak.');
    }

    private function detailRelations(): array
    {
        return [
            'student.user:id,name,email', 'student.program:id,name,code', 'student.academicAdvisor:id,name,nidn',
            'academicTerm:id,name,code,semester', 'period', 'reviewedBy:id,name', 'dispensationDecidedBy:id,name',
            'enrollments.classGroup.course:id,program_id,code,name,credits', 'enrollments.classGroup.lecturer:id,name,nidn', 'enrollments.classGroup.assignedRoom:id,name,code',
            'courseChangeRequests.classGroup.course:id,program_id,code,name,credits', 'courseChangeRequests.enrollment.classGroup.course:id,program_id,code,name,credits', 'courseChangeRequests.reviewedBy:id,name',
        ];
    }

    private function audit(Request $request, string $action, string $recordType, int $recordId, ?array $data): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id, 'module' => 'registration', 'action' => $action,
            'record_type' => $recordType, 'record_id' => (string) $recordId,
            'new_data' => $data ? json_encode($data) : null, 'ip_address' => $request->ip(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
