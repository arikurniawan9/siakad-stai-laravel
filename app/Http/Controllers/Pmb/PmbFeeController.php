<?php

namespace App\Http\Controllers\Pmb;

use App\Domain\Pmb\PmbFeeResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pmb\PmbFeeRequest;
use App\Models\AcademicTerm;
use App\Models\PmbApplication;
use App\Models\PmbFee;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PmbFeeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PmbFee::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'archive' => ['nullable', Rule::in(['available', 'archived'])]]);
        $search = trim((string) ($filters['q'] ?? ''));
        $archive = $filters['archive'] ?? 'available';
        $query = PmbFee::query()
            ->when($archive === 'archived', fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('wave', 'like', "%{$search}%")));

        return Inertia::render('Admin/PmbFees', [
            'filters' => ['q' => $search, 'archive' => $archive],
            'fees' => $query->with(['academicTerm:id,name,code,is_active', 'program:id,name,code'])->orderByDesc('is_active')->orderByDesc('id')->paginate(12)->withQueryString(),
            'applications' => PmbApplication::query()->with(['program:id,name,code', 'invoice:id,pmb_application_id,invoice_number,amount,paid_amount,due_at,status', 'invoice.virtualAccount:id,pmb_invoice_id,provider,va_number,status,expires_at'])->whereNot('status', 'draft')->latest('submitted_at')->limit(12)->get(['id', 'program_id', 'registration_number', 'full_name', 'registration_path', 'registration_type', 'registration_wave', 'status', 'submitted_at']),
            'termOptions' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'code', 'is_active']),
            'programOptions' => Program::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'summary' => ['activeFees' => PmbFee::query()->where('is_active', true)->count(), 'submitted' => PmbApplication::query()->where('status', 'submitted')->count(), 'unpaid' => DB::table('pmb_invoices')->where('status', 'unpaid')->count(), 'archived' => PmbFee::onlyTrashed()->count()],
            'abilities' => ['create' => $request->user()->can('create', PmbFee::class), 'update' => $request->user()->can('update', new PmbFee), 'delete' => $request->user()->can('delete', new PmbFee), 'verify' => $request->user()->can('pmb_verification.view'), 'simulatePayment' => app()->environment('local') && $request->user()->can('pmb_payments.update')],
        ]);
    }

    public function store(PmbFeeRequest $request, PmbFeeResolver $resolver): RedirectResponse
    {
        $fee = DB::transaction(function () use ($request, $resolver): PmbFee {
            $data = $request->validated();
            $resolver->ensureNoOverlap($data);
            $fee = PmbFee::create($data);
            $this->audit($request, 'fee_created', $fee->id, $fee->getAttributes());
            return $fee;
        }, 3);

        return back()->with('success', "Tarif {$fee->name} berhasil ditambahkan.");
    }

    public function update(PmbFeeRequest $request, PmbFee $fee, PmbFeeResolver $resolver): RedirectResponse
    {
        DB::transaction(function () use ($request, $fee, $resolver): void {
            $fee = PmbFee::query()->lockForUpdate()->findOrFail($fee->id);
            $old = $fee->getAttributes();
            $data = $request->validated();
            $resolver->ensureNoOverlap($data, $fee);
            $fee->update($data);
            $this->audit($request, 'fee_updated', $fee->id, ['old' => $old, 'new' => $fee->getAttributes()]);
        }, 3);

        return back()->with('success', 'Tarif PMB berhasil diperbarui.');
    }

    public function destroy(Request $request, PmbFee $fee): RedirectResponse
    {
        Gate::authorize('delete', $fee);
        DB::transaction(function () use ($request, $fee): void {
            $fee->delete();
            $this->audit($request, 'fee_archived', $fee->id, null);
        });
        return back()->with('success', 'Tarif PMB dipindahkan ke arsip.');
    }

    public function restore(Request $request, int $fee): RedirectResponse
    {
        $model = PmbFee::onlyTrashed()->findOrFail($fee);
        Gate::authorize('restore', $model);
        DB::transaction(function () use ($request, $model): void {
            $model->restore();
            $model->forceFill(['is_active' => false])->save();
            $this->audit($request, 'fee_restored', $model->id, ['is_active' => false]);
        });
        return back()->with('success', 'Tarif dipulihkan dalam keadaan nonaktif.');
    }

    private function audit(Request $request, string $action, int $id, ?array $data): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'pmb', 'action' => $action, 'record_type' => 'pmb_fee', 'record_id' => (string) $id, 'new_data' => $data ? json_encode($data) : null, 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
