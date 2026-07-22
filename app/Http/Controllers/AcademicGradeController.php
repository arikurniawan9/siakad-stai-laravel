<?php

namespace App\Http\Controllers;

use App\Domain\Academic\GradeSheetService;
use App\Http\Requests\GradeComponentRequest;
use App\Http\Requests\GradeScoresRequest;
use App\Http\Requests\PublishGradeSheetRequest;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\GradeComponent;
use App\Models\GradeSheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\NotificationService;

final class AcademicGradeController extends Controller
{
    public function index(Request $request, GradeSheetService $service): Response
    {
        abort_unless($request->user()->can('grades.view'), 403);
        $filters = $request->validate([
            'academic_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')],
            'q' => ['nullable', 'string', 'max:100'],
            'selected' => ['nullable', 'integer', 'exists:class_groups,id'],
        ]);
        $user = $request->user();
        $terms = AcademicTerm::query()->latest('starts_on')->get(['id', 'name', 'code', 'semester', 'is_active']);

        if ($user->active_role === 'Mahasiswa') {
            $student = $user->student()->with(['user:id,name,email', 'program:id,name,code', 'academicAdvisor:id,name,nidn'])->firstOrFail();
            $all = CourseEnrollment::query()
                ->with(['classGroup.course:id,program_id,code,name,credits', 'classGroup.academicTerm:id,name,code,semester,starts_on', 'classGroup.lecturer:id,name,nidn'])
                ->where('status', 'enrolled')->whereIn('grade_status', ['published', 'finalized'])
                ->whereHas('registration', fn (Builder $query) => $query->where('student_id', $student->id)->where('status', 'approved'))
                ->get()->sortByDesc(fn (CourseEnrollment $item) => $item->classGroup->academicTerm->starts_on?->timestamp ?? 0)->values();
            $selectedTermId = isset($filters['academic_term_id']) ? (int) $filters['academic_term_id'] : $all->first()?->classGroup?->academic_term_id;
            $khs = $all->where(fn (CourseEnrollment $item) => $item->classGroup->academic_term_id === $selectedTermId)->values();

            return Inertia::render('Academic/Grades', [
                'mode' => 'student', 'filters' => ['academic_term_id' => (string) ($selectedTermId ?? ''), 'q' => '', 'selected' => null],
                'termOptions' => $terms, 'student' => $student, 'khs' => $this->gradeRows($khs, $service),
                'transcript' => $this->gradeRows($all, $service), 'semesterSummaries' => $this->semesterSummaries($all, $service),
                'metrics' => $this->metrics($all, $service), 'gradeScale' => config('siakad.grade_scale'),
                'classGroups' => null, 'selectedClass' => null, 'abilities' => ['manage' => false, 'finalize' => false],
            ]);
        }

        abort_unless(in_array($user->active_role, ['Admin', 'Prodi', 'Dosen'], true), 403);
        $search = trim((string) ($filters['q'] ?? ''));
        $base = ClassGroup::query()
            ->with(['course:id,program_id,code,name,credits', 'course.program:id,name,code', 'academicTerm:id,name,code,semester,is_active', 'lecturer:id,name,nidn', 'gradeSheet:id,class_group_id,status,published_at,finalized_at'])
            ->withCount(['enrollments' => fn (Builder $query) => $query->where('status', 'enrolled')])
            ->whereHas('enrollments', fn (Builder $query) => $query->where('status', 'enrolled'))
            ->when(isset($filters['academic_term_id']), fn (Builder $query) => $query->where('academic_term_id', $filters['academic_term_id']))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhereHas('course', fn (Builder $query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))))
            ->when($user->active_role === 'Dosen', fn (Builder $query) => $query->where('lecturer_id', $user->lecturer?->id ?? 0));
        $selectedId = isset($filters['selected']) ? (clone $base)->whereKey($filters['selected'])->value('id') : (clone $base)->latest('academic_term_id')->value('id');
        $selected = $selectedId ? ClassGroup::query()->with([
            'course:id,program_id,code,name,credits', 'course.program:id,name,code', 'academicTerm:id,name,code,semester', 'lecturer:id,name,nidn',
            'gradeSheet.components.scores', 'gradeSheet.publishedBy:id,name', 'gradeSheet.finalizedBy:id,name',
            'enrollments' => fn ($query) => $query->where('status', 'enrolled')->with(['registration.student.user:id,name,email', 'registration.student.program:id,name,code', 'gradeScores']),
        ])->find($selectedId) : null;
        if ($selected) Gate::authorize('viewGrades', $selected);
        $scopedSheetIds = GradeSheet::query()->whereIn('class_group_id', (clone $base)->select('class_groups.id'));

        return Inertia::render('Academic/Grades', [
            'mode' => 'manager', 'filters' => ['academic_term_id' => (string) ($filters['academic_term_id'] ?? ''), 'q' => $search, 'selected' => $selectedId],
            'termOptions' => $terms, 'student' => null, 'khs' => [], 'transcript' => [], 'semesterSummaries' => [], 'metrics' => null,
            'gradeScale' => config('siakad.grade_scale'), 'classGroups' => $base->latest('academic_term_id')->paginate(12)->withQueryString(), 'selectedClass' => $selected,
            'summary' => ['classes' => (clone $base)->count(), 'draft' => (clone $scopedSheetIds)->where('status', 'draft')->count(), 'published' => (clone $scopedSheetIds)->where('status', 'published')->count(), 'finalized' => (clone $scopedSheetIds)->where('status', 'finalized')->count()],
            'abilities' => ['manage' => $selected ? $user->can('manageGrades', $selected) : false, 'finalize' => $selected ? $user->can('finalizeGrades', $selected) : false],
        ]);
    }

    public function storeComponent(GradeComponentRequest $request, ClassGroup $classGroup, GradeSheetService $service): RedirectResponse
    {
        $component = $service->addComponent($classGroup, $request->validated());
        $this->audit($request, 'grade_component_created', 'grade_component', $component->id, ['class_group_id' => $classGroup->id, ...$request->validated()]);

        return back()->with('success', 'Komponen nilai berhasil ditambahkan.');
    }

    public function updateComponent(GradeComponentRequest $request, ClassGroup $classGroup, GradeComponent $component, GradeSheetService $service): RedirectResponse
    {
        $old = $component->getAttributes();
        $service->updateComponent($classGroup, $component, $request->validated());
        $this->audit($request, 'grade_component_updated', 'grade_component', $component->id, ['old' => $old, 'new' => $request->validated()]);

        return back()->with('success', 'Komponen nilai berhasil diperbarui.');
    }

    public function destroyComponent(Request $request, ClassGroup $classGroup, GradeComponent $component, GradeSheetService $service): RedirectResponse
    {
        Gate::authorize('manageGrades', $classGroup);
        $service->removeComponent($classGroup, $component);
        $this->audit($request, 'grade_component_deleted', 'grade_component', $component->id, ['class_group_id' => $classGroup->id]);

        return back()->with('success', 'Komponen nilai berhasil dihapus.');
    }

    public function storeScores(GradeScoresRequest $request, ClassGroup $classGroup, CourseEnrollment $enrollment, GradeSheetService $service): RedirectResponse
    {
        $service->saveScores($classGroup, $enrollment, $request->validated('scores'), $request->user());
        $this->audit($request, 'student_scores_updated', 'course_enrollment', $enrollment->id, ['class_group_id' => $classGroup->id, 'component_ids' => array_map('intval', array_keys($request->validated('scores')))]);

        return back()->with('success', 'Nilai mahasiswa berhasil disimpan.');
    }

    public function publish(PublishGradeSheetRequest $request, ClassGroup $classGroup, GradeSheetService $service): RedirectResponse
    {
        $sheet = $service->publish($classGroup, $request->user(), $request->validated('notes'));
        app(NotificationService::class)->classStudents($classGroup, 'grades', 'Nilai telah dipublikasikan', 'Nilai '.$classGroup->course->name.' kini dapat dilihat pada KHS Anda.', '/academic/grades');
        $this->audit($request, 'grade_sheet_published', 'grade_sheet', $sheet->id, ['class_group_id' => $classGroup->id]);

        return back()->with('success', 'Nilai berhasil dipublikasikan kepada mahasiswa.');
    }

    public function finalize(Request $request, ClassGroup $classGroup, GradeSheetService $service): RedirectResponse
    {
        Gate::authorize('finalizeGrades', $classGroup);
        $sheet = $service->finalize($classGroup, $request->user());
        $this->audit($request, 'grade_sheet_finalized', 'grade_sheet', $sheet->id, ['class_group_id' => $classGroup->id]);

        return back()->with('success', 'Nilai berhasil difinalisasi dan dikunci.');
    }

    public function reopen(Request $request, ClassGroup $classGroup, GradeSheetService $service): RedirectResponse
    {
        Gate::authorize('finalizeGrades', $classGroup);
        $sheet = $service->reopen($classGroup);
        $this->audit($request, 'grade_sheet_reopened', 'grade_sheet', $sheet->id, ['class_group_id' => $classGroup->id]);

        return back()->with('success', 'Nilai dibuka kembali menjadi draf.');
    }

    private function gradeRows(Collection $items, GradeSheetService $service): array
    {
        return $items->map(fn (CourseEnrollment $item): array => [
            'id' => $item->id, 'credits' => $item->credits, 'final_score' => $item->final_score, 'letter_grade' => $item->letter_grade,
            'grade_status' => $item->grade_status, 'grade_points' => $service->pointsFor($item->letter_grade),
            'course' => $item->classGroup->course, 'academic_term' => $item->classGroup->academicTerm, 'class_name' => $item->classGroup->name,
            'lecturer' => $item->classGroup->lecturer,
        ])->values()->all();
    }

    private function metrics(Collection $items, GradeSheetService $service): array
    {
        $credits = (int) $items->sum('credits');
        $weighted = $items->sum(fn (CourseEnrollment $item): float => $service->pointsFor($item->letter_grade) * $item->credits);

        return ['credits' => $credits, 'courses' => $items->count(), 'gpa' => $credits ? round($weighted / $credits, 2) : 0];
    }

    private function semesterSummaries(Collection $items, GradeSheetService $service): array
    {
        return $items->groupBy(fn (CourseEnrollment $item) => $item->classGroup->academic_term_id)->map(function (Collection $rows) use ($service): array {
            $metrics = $this->metrics($rows, $service);
            return ['academic_term' => $rows->first()->classGroup->academicTerm, ...$metrics];
        })->values()->all();
    }

    private function audit(Request $request, string $action, string $recordType, int $recordId, array $data): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'grades', 'action' => $action, 'record_type' => $recordType, 'record_id' => (string) $recordId, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
