<?php

namespace App\Http\Controllers\Pmb;

use App\Domain\Pmb\PmbSelectionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pmb\PmbSelectionCandidateRequest;
use App\Http\Requests\Pmb\PmbSelectionComponentRequest;
use App\Http\Requests\Pmb\PmbSelectionRequest;
use App\Http\Requests\Pmb\PmbSelectionScoreRequest;
use App\Models\AcademicTerm;
use App\Models\PmbApplication;
use App\Models\PmbSelection;
use App\Models\PmbSelectionComponent;
use App\Models\PmbSelectionResult;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PmbSelectionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PmbSelection::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'finalized'])],
            'selected' => ['nullable', 'integer', 'exists:pmb_selections,id'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? null;
        $base = PmbSelection::query()
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhereHas('program', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))))
            ->when($status, fn (Builder $query) => $query->where('status', $status));
        $selectedId = isset($filters['selected']) ? (clone $base)->whereKey($filters['selected'])->value('id') : (clone $base)->latest('starts_at')->value('id');
        $selected = $selectedId ? PmbSelection::query()->with([
            'academicTerm:id,name,code,starts_on',
            'program:id,name,code',
            'components',
            'results.application:id,user_id,program_id,pmb_fee_id,registration_number,full_name,email,phone,registration_path,registration_type,status',
            'results.application.program:id,name,code',
            'results.application.invoice:id,pmb_application_id,invoice_number,amount,paid_amount,status',
            'results.scores',
            'results.student:id,pmb_application_id,nim,status',
            'finalizedBy:id,name',
        ])->find($selectedId) : null;

        $applicationOptions = collect();
        if ($selected && $selected->status === 'draft') {
            $applicationOptions = PmbApplication::query()
                ->with('program:id,name,code')
                ->where('status', 'verified')
                ->whereHas('invoice', fn (Builder $query) => $query->whereIn('status', ['paid', 'waived']))
                ->whereHas('fee', fn (Builder $query) => $query->where('academic_term_id', $selected->academic_term_id))
                ->whereDoesntHave('selectionResult')
                ->when($selected->program_id, fn (Builder $query) => $query->where('program_id', $selected->program_id))
                ->orderBy('registration_number')
                ->limit(100)
                ->get(['id', 'program_id', 'registration_number', 'full_name']);
        }

        return Inertia::render('Admin/PmbSelection', [
            'filters' => ['q' => $search, 'status' => $status ?? '', 'selected' => $selectedId],
            'selections' => (clone $base)->with(['academicTerm:id,name,code', 'program:id,name,code'])->withCount('results')->latest('starts_at')->paginate(10)->withQueryString(),
            'selectedSelection' => $selected,
            'termOptions' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'code', 'starts_on']),
            'programOptions' => Program::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'applicationOptions' => $applicationOptions,
            'summary' => [
                'draft' => PmbSelection::query()->where('status', 'draft')->count(),
                'finalized' => PmbSelection::query()->where('status', 'finalized')->count(),
                'candidates' => PmbSelectionResult::query()->count(),
                'accepted' => PmbSelectionResult::query()->where('decision', 'accepted')->count(),
            ],
            'abilities' => [
                'create' => $request->user()->can('create', PmbSelection::class),
                'update' => $selected ? $request->user()->can('update', $selected) : false,
                'delete' => $selected ? $request->user()->can('delete', $selected) : false,
                'convert' => $selected ? $request->user()->can('convert', $selected) : false,
            ],
        ]);
    }

    public function store(PmbSelectionRequest $request): RedirectResponse
    {
        $selection = DB::transaction(function () use ($request): PmbSelection {
            $selection = PmbSelection::create($request->validated());
            $this->audit($request, 'selection_created', 'pmb_selection', $selection->id, $selection->getAttributes());

            return $selection;
        });

        return to_route('admin.pmb.selection', ['selected' => $selection->id])->with('success', 'Jadwal seleksi berhasil dibuat.');
    }

    public function update(PmbSelectionRequest $request, PmbSelection $selection): RedirectResponse
    {
        DB::transaction(function () use ($request, $selection): void {
            $selection = PmbSelection::query()->lockForUpdate()->findOrFail($selection->id);
            if ($selection->status !== 'draft') throw ValidationException::withMessages(['selection' => 'Seleksi yang sudah difinalisasi tidak dapat diubah.']);
            $old = $selection->getAttributes();
            $selection->update($request->validated());
            $this->audit($request, 'selection_updated', 'pmb_selection', $selection->id, ['old' => $old, 'new' => $selection->getAttributes()]);
        }, 3);

        return back()->with('success', 'Jadwal seleksi berhasil diperbarui.');
    }

    public function destroy(Request $request, PmbSelection $selection): RedirectResponse
    {
        Gate::authorize('delete', $selection);
        DB::transaction(function () use ($request, $selection): void {
            $selection = PmbSelection::query()->lockForUpdate()->findOrFail($selection->id);
            if ($selection->status !== 'draft') throw ValidationException::withMessages(['selection' => 'Seleksi yang sudah difinalisasi tidak dapat dihapus.']);
            $selection->delete();
            $this->audit($request, 'selection_deleted', 'pmb_selection', $selection->id, null);
        }, 3);

        return to_route('admin.pmb.selection')->with('success', 'Jadwal seleksi berhasil dihapus.');
    }

    public function storeComponent(PmbSelectionComponentRequest $request, PmbSelection $selection, PmbSelectionService $service): RedirectResponse
    {
        $component = $service->addComponent($selection, $request->validated());
        $this->audit($request, 'component_created', 'pmb_selection_component', $component->id, ['selection_id' => $selection->id, 'name' => $component->name, 'weight' => $component->weight]);

        return back()->with('success', 'Komponen seleksi berhasil ditambahkan.');
    }

    public function destroyComponent(Request $request, PmbSelection $selection, PmbSelectionComponent $component, PmbSelectionService $service): RedirectResponse
    {
        Gate::authorize('update', $selection);
        $service->removeComponent($selection, $component);
        $this->audit($request, 'component_deleted', 'pmb_selection_component', $component->id, ['selection_id' => $selection->id]);

        return back()->with('success', 'Komponen seleksi berhasil dihapus.');
    }

    public function assignCandidate(PmbSelectionCandidateRequest $request, PmbSelection $selection, PmbSelectionService $service): RedirectResponse
    {
        $application = PmbApplication::query()->findOrFail($request->integer('pmb_application_id'));
        $result = $service->assignCandidate($selection, $application);
        $this->audit($request, 'candidate_assigned', 'pmb_selection_result', $result->id, ['selection_id' => $selection->id, 'application_id' => $application->id]);

        return back()->with('success', 'Calon peserta berhasil ditambahkan.');
    }

    public function removeCandidate(Request $request, PmbSelection $selection, PmbSelectionResult $result, PmbSelectionService $service): RedirectResponse
    {
        Gate::authorize('update', $selection);
        $service->removeCandidate($selection, $result);
        $this->audit($request, 'candidate_removed', 'pmb_selection_result', $result->id, ['selection_id' => $selection->id]);

        return back()->with('success', 'Calon peserta dikeluarkan dari jadwal seleksi.');
    }

    public function storeScores(PmbSelectionScoreRequest $request, PmbSelection $selection, PmbSelectionResult $result, PmbSelectionService $service): RedirectResponse
    {
        $service->saveScores($selection, $result, $request->validated('scores'));
        $this->audit($request, 'scores_updated', 'pmb_selection_result', $result->id, ['selection_id' => $selection->id, 'component_ids' => array_map('intval', array_keys($request->validated('scores')))]);

        return back()->with('success', 'Nilai peserta berhasil disimpan.');
    }

    public function finalize(Request $request, PmbSelection $selection, PmbSelectionService $service): RedirectResponse
    {
        Gate::authorize('update', $selection);
        $service->finalize($selection, $request->user());
        $this->audit($request, 'selection_finalized', 'pmb_selection', $selection->id, ['status' => 'finalized']);

        return back()->with('success', 'Seleksi berhasil difinalisasi dan hasil peserta telah dikunci.');
    }

    public function convert(Request $request, PmbSelection $selection, PmbSelectionResult $result, PmbSelectionService $service): RedirectResponse
    {
        Gate::authorize('convert', $selection);
        $student = $service->convert($selection, $result, $request->user());
        $this->audit($request, 'candidate_converted', 'pmb_application', $result->pmb_application_id, ['selection_id' => $selection->id, 'student_id' => $student->id, 'nim' => $student->nim]);
        $this->audit($request, 'created_from_pmb', 'student', $student->id, ['pmb_application_id' => $result->pmb_application_id, 'nim' => $student->nim], 'students');

        return back()->with('success', "Calon mahasiswa berhasil dikonversi dengan NIM {$student->nim}.");
    }

    private function audit(Request $request, string $action, string $recordType, int $recordId, ?array $data, string $module = 'pmb'): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => $module,
            'action' => $action,
            'record_type' => $recordType,
            'record_id' => (string) $recordId,
            'new_data' => $data ? json_encode($data) : null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
