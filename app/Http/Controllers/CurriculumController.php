<?php

namespace App\Http\Controllers;

use App\Http\Requests\CoursePrerequisiteRequest;
use App\Http\Requests\CurriculumCourseRequest;
use App\Http\Requests\CurriculumRequest;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CoursePrerequisite;
use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CurriculumController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Curriculum::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'status' => ['nullable', Rule::in(['available', 'archived'])],
            'selected' => ['nullable', 'integer'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $programId = $filters['program_id'] ?? null;
        $status = $filters['status'] ?? 'available';

        $baseQuery = Curriculum::query()
            ->when($status === 'archived', fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($programId, fn (Builder $query) => $query->where('program_id', $programId));

        $selectedId = isset($filters['selected'])
            ? (clone $baseQuery)->whereKey($filters['selected'])->value('id')
            : (clone $baseQuery)->orderByDesc('is_active')->orderByDesc('id')->value('id');

        $selected = $selectedId ? Curriculum::withTrashed()
            ->with([
                'program:id,name,code',
                'effectiveTerm:id,name,code',
                'curriculumCourses' => fn ($query) => $query->with('course:id,program_id,code,name,type')->orderBy('semester')->orderBy('id'),
            ])
            ->find($selectedId) : null;

        $curricula = (clone $baseQuery)
            ->with(['program:id,name,code', 'effectiveTerm:id,name,code'])
            ->withCount('curriculumCourses')
            ->withSum('curriculumCourses as assigned_credits', 'credits')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $selectedProgramId = $selected?->program_id;

        return Inertia::render('Admin/Curricula', [
            'filters' => [
                'q' => $search,
                'program_id' => $programId ? (string) $programId : '',
                'status' => $status,
                'selected' => $selectedId,
            ],
            'curricula' => $curricula,
            'selectedCurriculum' => $selected,
            'programOptions' => Program::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'termOptions' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'courseOptions' => Course::query()
                ->where('is_active', true)
                ->when($selectedProgramId, fn (Builder $query) => $query->where('program_id', $selectedProgramId))
                ->orderBy('code')
                ->get(['id', 'program_id', 'code', 'name', 'credits']),
            'prerequisites' => $selectedProgramId ? CoursePrerequisite::query()
                ->whereHas('course', fn (Builder $query) => $query->where('program_id', $selectedProgramId))
                ->with(['course:id,code,name', 'prerequisiteCourse:id,code,name'])
                ->orderBy('course_id')
                ->get() : [],
            'summary' => [
                'available' => Curriculum::query()->count(),
                'active' => Curriculum::query()->where('is_active', true)->count(),
                'mapped_courses' => CurriculumCourse::query()->count(),
                'prerequisites' => CoursePrerequisite::query()->count(),
                'archived' => Curriculum::onlyTrashed()->count(),
            ],
            'abilities' => [
                'create' => $request->user()->can('create', Curriculum::class),
                'update' => $request->user()->can('manageCourses', Curriculum::class),
                'delete' => $request->user()->can('curricula.delete'),
            ],
        ]);
    }

    public function store(CurriculumRequest $request): RedirectResponse
    {
        Gate::authorize('create', Curriculum::class);

        $curriculum = DB::transaction(function () use ($request): Curriculum {
            $data = $request->validated();
            $this->lockProgramAndDeactivateOthers($data);
            $curriculum = Curriculum::create($data);
            $this->audit($request, 'created', 'curriculum', $curriculum->id, null, $curriculum->getAttributes());

            return $curriculum;
        });

        return to_route('admin.curricula', ['selected' => $curriculum->id])->with('success', 'Kurikulum berhasil ditambahkan.');
    }

    public function update(CurriculumRequest $request, Curriculum $curriculum): RedirectResponse
    {
        Gate::authorize('update', $curriculum);

        DB::transaction(function () use ($request, $curriculum): void {
            $data = $request->validated();
            $old = $curriculum->getAttributes();
            $this->lockProgramAndDeactivateOthers($data, $curriculum->id);
            $curriculum->update($data);
            $this->audit($request, 'updated', 'curriculum', $curriculum->id, $old, $curriculum->fresh()->getAttributes());
        });

        return back()->with('success', 'Kurikulum berhasil diperbarui.');
    }

    public function destroy(Request $request, Curriculum $curriculum): RedirectResponse
    {
        Gate::authorize('delete', $curriculum);

        DB::transaction(function () use ($request, $curriculum): void {
            $old = $curriculum->getAttributes();
            $curriculum->update(['is_active' => false]);
            $curriculum->delete();
            $this->audit($request, 'archived', 'curriculum', $curriculum->id, $old, null);
        });

        return to_route('admin.curricula')->with('success', 'Kurikulum dipindahkan ke arsip.');
    }

    public function restore(Request $request, int $curriculum): RedirectResponse
    {
        $model = Curriculum::onlyTrashed()->findOrFail($curriculum);
        Gate::authorize('restore', $model);

        DB::transaction(function () use ($request, $model): void {
            $model->restore();
            $this->audit($request, 'restored', 'curriculum', $model->id, null, $model->fresh()->getAttributes());
        });

        return to_route('admin.curricula', ['selected' => $model->id])->with('success', 'Kurikulum berhasil dipulihkan dalam status nonaktif.');
    }

    public function storeCourse(CurriculumCourseRequest $request, Curriculum $curriculum): RedirectResponse
    {
        Gate::authorize('update', $curriculum);

        DB::transaction(function () use ($request, $curriculum): void {
            $item = $curriculum->curriculumCourses()->create($request->validated());
            $this->audit($request, 'course_attached', 'curriculum_course', $item->id, null, $item->getAttributes());
        });

        return back()->with('success', 'Mata kuliah ditambahkan ke kurikulum.');
    }

    public function updateCourse(CurriculumCourseRequest $request, Curriculum $curriculum, CurriculumCourse $item): RedirectResponse
    {
        abort_unless($item->curriculum_id === $curriculum->id, 404);
        Gate::authorize('update', $curriculum);

        DB::transaction(function () use ($request, $item): void {
            $old = $item->getAttributes();
            $item->update($request->validated());
            $this->audit($request, 'course_updated', 'curriculum_course', $item->id, $old, $item->fresh()->getAttributes());
        });

        return back()->with('success', 'Pemetaan mata kuliah diperbarui.');
    }

    public function destroyCourse(Request $request, Curriculum $curriculum, CurriculumCourse $item): RedirectResponse
    {
        abort_unless($item->curriculum_id === $curriculum->id, 404);
        Gate::authorize('update', $curriculum);

        DB::transaction(function () use ($request, $item): void {
            $old = $item->getAttributes();
            $id = $item->id;
            $item->delete();
            $this->audit($request, 'course_detached', 'curriculum_course', $id, $old, null);
        });

        return back()->with('success', 'Mata kuliah dihapus dari kurikulum.');
    }

    public function storePrerequisite(CoursePrerequisiteRequest $request): RedirectResponse
    {
        Gate::authorize('manageCourses', Curriculum::class);

        DB::transaction(function () use ($request): void {
            $prerequisite = CoursePrerequisite::create($request->validated());
            $this->audit($request, 'prerequisite_created', 'course_prerequisite', $prerequisite->id, null, $prerequisite->getAttributes());
        });

        return back()->with('success', 'Prasyarat mata kuliah berhasil ditambahkan.');
    }

    public function destroyPrerequisite(Request $request, CoursePrerequisite $prerequisite): RedirectResponse
    {
        Gate::authorize('manageCourses', Curriculum::class);

        DB::transaction(function () use ($request, $prerequisite): void {
            $old = $prerequisite->getAttributes();
            $id = $prerequisite->id;
            $prerequisite->delete();
            $this->audit($request, 'prerequisite_deleted', 'course_prerequisite', $id, $old, null);
        });

        return back()->with('success', 'Prasyarat mata kuliah dihapus.');
    }

    private function lockProgramAndDeactivateOthers(array $data, ?int $exceptId = null): void
    {
        if (! ($data['is_active'] ?? false)) {
            return;
        }

        Program::query()->whereKey($data['program_id'])->lockForUpdate()->firstOrFail();
        Curriculum::query()
            ->where('program_id', $data['program_id'])
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->update(['is_active' => false]);
    }

    private function audit(Request $request, string $action, string $type, int $id, ?array $old, ?array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => 'curricula',
            'action' => $action,
            'record_type' => $type,
            'record_id' => (string) $id,
            'old_data' => $old ? json_encode($old) : null,
            'new_data' => $new ? json_encode($new) : null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
