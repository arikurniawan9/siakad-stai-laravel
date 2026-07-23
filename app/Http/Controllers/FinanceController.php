<?php

namespace App\Http\Controllers;

use App\Domain\Finance\StudentFinanceService;
use App\Http\Requests\BillingItemRequest;
use App\Http\Requests\ManualPaymentRequest;
use App\Models\AcademicTerm;
use App\Models\BillingItem;
use App\Models\Payment;
use App\Models\PaymentVirtualAccount;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('finance.view'), 403);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:unpaid,partial,paid,waived'], 'academic_term_id' => ['nullable', 'integer', 'exists:academic_terms,id'], 'student_id' => ['nullable', 'integer', 'exists:students,id'], 'tab' => ['nullable', 'in:bills,payments,reconciliation']]);
        $user = $request->user(); $isStudent = $user->active_role === 'Mahasiswa';
        abort_unless($isStudent || in_array($user->active_role, ['Admin', 'Keuangan', 'Bendahara', 'Pimpinan'], true), 403);
        $studentId = $isStudent ? ($user->student?->id ?? 0) : ($filters['student_id'] ?? null);
        $search = trim((string) ($filters['q'] ?? ''));
        $billQuery = BillingItem::query()->with(['student.user:id,name,email', 'student.program:id,code,name', 'academicTerm:id,code,name,semester', 'creator:id,name', 'waivedBy:id,name'])
            ->when($isStudent, fn (Builder $query) => $query->where('student_id', $studentId))
            ->when(! $isStudent && $studentId, fn (Builder $query) => $query->where('student_id', $studentId))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['academic_term_id']), fn (Builder $query) => $query->where('academic_term_id', $filters['academic_term_id']))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('invoice_number', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")->orWhereHas('student', fn (Builder $query) => $query->where('nim', 'like', "%{$search}%")->orWhereHas('user', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")))));
        $paymentQuery = Payment::query()->with(['student.user:id,name', 'student:id,user_id,nim', 'allocations.billingItem:id,invoice_number,description'])
            ->when($isStudent, fn (Builder $query) => $query->where('student_id', $studentId))->when(! $isStudent && $studentId, fn (Builder $query) => $query->where('student_id', $studentId));
        $summaryBase = clone $billQuery;
        $summary = ['billed' => (float) (clone $summaryBase)->sum('amount'), 'paid' => (float) (clone $summaryBase)->sum('paid_amount'), 'outstanding' => (float) (clone $summaryBase)->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('amount - paid_amount')), 'overdue' => (clone $summaryBase)->whereIn('status', ['unpaid', 'partial'])->whereDate('due_on', '<', today())->count()];
        $virtualAccount = $studentId ? PaymentVirtualAccount::query()->where('student_id', $studentId)->latest()->first() : null;

        return Inertia::render('Finance/Index', [
            'mode' => $isStudent ? 'student' : 'manager', 'filters' => ['q' => $search, 'status' => $filters['status'] ?? '', 'academic_term_id' => (string) ($filters['academic_term_id'] ?? ''), 'student_id' => $studentId, 'tab' => $filters['tab'] ?? 'bills'],
            'bills' => $billQuery->latest('due_on')->paginate(15)->withQueryString(), 'payments' => $paymentQuery->latest('paid_at')->limit(50)->get(), 'summary' => $summary, 'virtualAccount' => $virtualAccount,
            'termOptions' => AcademicTerm::query()->latest('starts_on')->get(['id', 'code', 'name', 'semester', 'is_active']),
            'studentOptions' => $isStudent ? [] : Student::query()->with(['user:id,name', 'program:id,code'])->where('status', 'Aktif')->orderBy('nim')->get(['id', 'user_id', 'program_id', 'nim']),
            'viewerStudentId' => $isStudent ? $studentId : null,
            'reconciliation' => $isStudent ? null : ['events' => DB::table('bank_webhook_events')->latest()->limit(50)->get(), 'summary' => DB::table('bank_webhook_events')->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')],
            'abilities' => ['manage' => ! $isStudent && in_array($user->active_role, ['Admin', 'Keuangan', 'Bendahara'], true) && $user->can('billing.update')],
        ]);
    }

    public function storeBill(BillingItemRequest $request, StudentFinanceService $service): RedirectResponse
    {
        $this->authorizeManager($request); $student = Student::findOrFail($request->validated('student_id')); $term = AcademicTerm::findOrFail($request->validated('academic_term_id'));
        $bill = $service->createBill($student, $term, $request->safe()->except(['student_id', 'academic_term_id']), $request->user());
        $this->audit($request, 'bill_created', 'billing_item', $bill->id, ['invoice_number' => $bill->invoice_number, 'amount' => $bill->amount]);
        return back()->with('success', 'Tagihan mahasiswa berhasil diterbitkan.');
    }

    public function waive(Request $request, BillingItem $bill, StudentFinanceService $service): RedirectResponse
    {
        $this->authorizeManager($request); $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:5000']]); $service->waive($bill, $data['reason'], $request->user());
        $this->audit($request, 'bill_waived', 'billing_item', $bill->id, ['reason' => $data['reason']]); return back()->with('success', 'Tagihan berhasil dibebaskan dengan jejak persetujuan.');
    }

    public function recordPayment(ManualPaymentRequest $request, BillingItem $bill, StudentFinanceService $service): RedirectResponse
    {
        $this->authorizeManager($request); $payment = $service->recordManualPayment($bill, $request->validated(), $request->user());
        $this->audit($request, 'manual_payment_recorded', 'payment', $payment->id, ['billing_item_id' => $bill->id, 'amount' => $payment->amount]); return back()->with('success', 'Pembayaran manual berhasil dicatat dan dialokasikan.');
    }

    public function issueVa(Request $request, Student $student, StudentFinanceService $service): RedirectResponse
    {
        abort_unless($request->user()->can('finance.view'), 403);
        abort_unless($request->user()->active_role === 'Mahasiswa' ? (int) $request->user()->student?->id === (int) $student->id : in_array($request->user()->active_role, ['Admin', 'Keuangan', 'Bendahara'], true), 403);
        $va = $service->issueVirtualAccount($student); $this->audit($request, 'student_va_issued', 'student', $student->id, ['va_id' => $va->id, 'provider' => $va->provider]);
        app(\App\Services\NotificationService::class)->student($student, 'finance', 'Virtual Account aktif', 'Virtual Account '.$va->provider.' Anda sudah siap digunakan untuk pembayaran tagihan.', '/finance');
        return back()->with('success', 'Virtual Account mahasiswa siap digunakan.');
    }

    private function authorizeManager(Request $request): void { abort_unless(in_array($request->user()->active_role, ['Admin', 'Keuangan', 'Bendahara'], true) && $request->user()->can('billing.update'), 403); }
    private function audit(Request $request, string $action, string $type, int $id, array $data): void { DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'finance', 'action' => $action, 'record_type' => $type, 'record_id' => (string) $id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]); }
}
