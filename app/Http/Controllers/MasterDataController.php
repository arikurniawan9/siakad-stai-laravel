<?php

namespace App\Http\Controllers;

use App\Domain\MasterData\MasterDataTransferService;
use App\Http\Requests\MasterDataRequest;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Program;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class MasterDataController extends Controller
{
    private const RESOURCES = [
        'campuses' => Campus::class,
        'faculties' => Faculty::class,
        'programs' => Program::class,
        'academic-terms' => AcademicTerm::class,
        'courses' => Course::class,
    ];

    public function index(Request $request, MasterDataTransferService $transferService): Response
    {
        foreach (self::RESOURCES as $class) Gate::authorize('viewAny', $class);
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'import' => ['nullable', 'uuid']]);
        $search = trim($request->string('q')->toString());
        $like = fn ($query) => $search === '' ? $query : $query->where(function ($query) use ($search): void {
            $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
        });

        $importToken = $validated['import'] ?? null;
        $storedPreview = $importToken ? $request->session()->get("master_data_imports.{$importToken}") : null;
        $importPreview = is_array($storedPreview) && ($storedPreview['user_id'] ?? null) === $request->user()->id
            ? $transferService->present($storedPreview, $importToken)
            : null;

        return Inertia::render('Admin/MasterData', [
            'filters' => ['q' => $search],
            'campuses' => $like(Campus::query())->withCount('faculties')->orderBy('name')->paginate(8, ['id', 'name', 'code', 'address', 'is_active'])->withQueryString(),
            'faculties' => $like(Faculty::query())->with('campus:id,name')->withCount('programs')->orderBy('name')->paginate(8, ['id', 'campus_id', 'name', 'code'])->withQueryString(),
            'programs' => $like(Program::query())->with('faculty:id,name')->withCount('courses')->orderBy('name')->paginate(8, ['id', 'faculty_id', 'name', 'code', 'degree', 'is_active'])->withQueryString(),
            'academicTerms' => AcademicTerm::query()->when($search, fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))->orderByDesc('starts_on')->paginate(8, ['id', 'name', 'code', 'semester', 'starts_on', 'ends_on', 'is_active'])->withQueryString(),
            'courses' => $like(Course::query())->with('program:id,name')->orderBy('code')->paginate(8, ['id', 'program_id', 'code', 'name', 'credits', 'type', 'is_active'])->withQueryString(),
            'importPreview' => $importPreview,
            'transferAbilities' => collect(array_keys(self::RESOURCES))->mapWithKeys(function (string $resource) use ($request): array {
                $class = self::RESOURCES[$resource];
                return [$resource => ['import' => $request->user()->can('create', $class) && $request->user()->can('update', new $class), 'export' => $request->user()->can('viewAny', $class)]];
            }),
        ]);
    }

    public function store(MasterDataRequest $request, string $resource): RedirectResponse
    {
        $this->ensureResource($resource);
        DB::transaction(function () use ($request, $resource): void {
            $class = self::RESOURCES[$resource];
            $model = $class::create($request->validated());
            $this->activateExclusiveTerm($resource, $model);
            $this->audit($request, 'created', $resource, $model->getKey(), $model->getAttributes());
        });

        return back()->with('success', 'Data master berhasil ditambahkan.');
    }

    public function update(MasterDataRequest $request, string $resource, int $id): RedirectResponse
    {
        $this->ensureResource($resource);
        DB::transaction(function () use ($request, $resource, $id): void {
            $model = self::RESOURCES[$resource]::query()->findOrFail($id);
            $old = $model->getAttributes();
            $model->update($request->validated());
            $this->activateExclusiveTerm($resource, $model);
            $this->audit($request, 'updated', $resource, $model->getKey(), ['old' => $old, 'new' => $model->getAttributes()]);
        });

        return back()->with('success', 'Data master berhasil diperbarui.');
    }

    public function destroy(Request $request, string $resource, int $id): RedirectResponse
    {
        $this->ensureResource($resource);
        $model = self::RESOURCES[$resource]::query()->findOrFail($id);
        Gate::authorize('delete', $model);
        $model->delete();
        $this->audit($request, 'deleted', $resource, $id, null);

        return back()->with('success', 'Data master dipindahkan ke arsip.');
    }

    private function ensureResource(string $resource): void
    {
        abort_unless(array_key_exists($resource, self::RESOURCES), 404);
    }

    private function activateExclusiveTerm(string $resource, object $model): void
    {
        if ($resource === 'academic-terms' && $model->is_active) {
            AcademicTerm::query()->whereKeyNot($model->getKey())->update(['is_active' => false]);
        }
    }

    private function audit(Request $request, string $action, string $resource, int $id, ?array $data): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => 'master_data',
            'action' => $action,
            'record_type' => $resource,
            'record_id' => (string) $id,
            'new_data' => $data ? json_encode($data) : null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
