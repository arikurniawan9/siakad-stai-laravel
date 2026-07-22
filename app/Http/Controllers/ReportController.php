<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('reports.view'), 403);
        $filters = $request->validate(['academic_term_id' => ['nullable', 'integer', 'exists:academic_terms,id']]);
        $termId = isset($filters['academic_term_id']) ? (int) $filters['academic_term_id'] : AcademicTerm::query()->where('is_active', true)->value('id');
        $bill = DB::table('billing_items')->when($termId, fn ($query) => $query->where('academic_term_id', $termId));
        $registration = DB::table('semester_registrations')->when($termId, fn ($query) => $query->where('academic_term_id', $termId));
        $enrollment = DB::table('course_enrollments')->when($termId, fn ($query) => $query->whereIn('semester_registration_id', DB::table('semester_registrations')->select('id')->where('academic_term_id', $termId)));
        $payments = DB::table('payments')->whereNotNull('paid_at')->where('paid_at', '>=', now()->subMonths(5)->startOfMonth())->get(['amount', 'paid_at'])->groupBy(fn ($item) => substr((string) $item->paid_at, 0, 7))->map(fn ($rows, $month) => ['month' => $month, 'amount' => (float) $rows->sum('amount')])->values();
        $edom = DB::table('edom_responses')->when($termId, fn ($query) => $query->whereIn('edom_questionnaire_id', DB::table('edom_questionnaires')->select('id')->where('academic_term_id', $termId)));
        return Inertia::render('Reports/Index', [
            'filters' => ['academic_term_id' => (string) ($termId ?? '')], 'termOptions' => AcademicTerm::query()->latest('starts_on')->get(['id', 'code', 'name', 'semester', 'is_active']),
            'academic' => ['active_students' => DB::table('students')->where('status', 'Aktif')->count(), 'registrations' => (clone $registration)->count(), 'approved_registrations' => (clone $registration)->where('status', 'approved')->count(), 'enrollments' => (clone $enrollment)->where('status', 'enrolled')->count(), 'average_score' => round((float) (clone $enrollment)->whereIn('grade_status', ['published', 'finalized'])->avg('final_score'), 2)],
            'finance' => ['billed' => (float) (clone $bill)->sum('amount'), 'paid' => (float) (clone $bill)->sum('paid_amount'), 'outstanding' => (float) (clone $bill)->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('amount - paid_amount')), 'collection_rate' => ($total = (float) (clone $bill)->sum('amount')) > 0 ? round((float) (clone $bill)->sum('paid_amount') / $total * 100, 1) : 0, 'payment_trend' => $payments],
            'pmb' => ['applications' => DB::table('pmb_applications')->count(), 'submitted' => DB::table('pmb_applications')->whereNotNull('submitted_at')->count(), 'verified' => DB::table('pmb_applications')->where('status', 'verified')->count(), 'converted' => DB::table('pmb_selection_results')->whereNotNull('converted_student_id')->count()],
            'quality' => ['responses' => (clone $edom)->count(), 'average' => round((float) (clone $edom)->avg('average_score'), 2), 'classes' => (clone $edom)->distinct()->count('class_group_id')],
        ]);
    }
}
