<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $role = $request->user()->active_role ?: $request->user()->getRoleNames()->first() ?: 'Admin';
        $recentPayments = DB::table('payments')->when($role === 'Mahasiswa', fn ($query) => $query->where('student_id', $request->user()->student?->id ?? 0))->latest('paid_at')->limit(5)->get(['external_reference', 'amount', 'status', 'paid_at']);
        return Inertia::render('Dashboard/Index', [
            'role' => $role, 'stats' => $this->statsFor($request, $role),
            'term' => DB::table('academic_terms')->where('is_active', true)->first(['name', 'semester']), 'recentPayments' => $recentPayments,
            'system' => ['users' => User::query()->where('is_active', true)->count(), 'database' => 'connected', 'bank' => config('bsi.enabled') ? 'configured' : 'awaiting contract'],
        ]);
    }

    private function statsFor(Request $request, string $role): array
    {
        if ($role === 'Mahasiswa') {
            $studentId = $request->user()->student?->id ?? 0; $registrationIds = DB::table('semester_registrations')->where('student_id', $studentId)->pluck('id'); $enrollments = DB::table('course_enrollments')->whereIn('semester_registration_id', $registrationIds)->where('status', 'enrolled');
            return [
                ['label' => 'Kelas aktif', 'value' => number_format((clone $enrollments)->count()), 'detail' => 'mata kuliah diikuti', 'tone' => 'blue'],
                ['label' => 'Sisa tagihan', 'value' => 'Rp '.number_format((float) DB::table('billing_items')->where('student_id', $studentId)->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('amount - paid_amount')), 0, ',', '.'), 'detail' => 'ledger pribadi', 'tone' => 'amber'],
                ['label' => 'Nilai terbit', 'value' => number_format((clone $enrollments)->whereIn('grade_status', ['published', 'finalized'])->count()), 'detail' => 'dapat dilihat di KHS', 'tone' => 'violet'],
                ['label' => 'Tugas berjalan', 'value' => number_format(DB::table('lms_assignments')->whereIn('class_group_id', (clone $enrollments)->pluck('class_group_id'))->where('is_published', true)->where('due_at', '>=', now())->count()), 'detail' => 'belum melewati tenggat', 'tone' => 'emerald'],
            ];
        }
        if ($role === 'Dosen') {
            $lecturerId = $request->user()->lecturer?->id ?? 0; $classIds = DB::table('class_groups')->where('lecturer_id', $lecturerId)->pluck('id');
            return [
                ['label' => 'Kelas diampu', 'value' => number_format($classIds->count()), 'detail' => 'seluruh semester', 'tone' => 'blue'],
                ['label' => 'Mahasiswa bimbingan', 'value' => number_format(DB::table('students')->where('academic_advisor_id', $lecturerId)->where('status', 'Aktif')->count()), 'detail' => 'status aktif', 'tone' => 'amber'],
                ['label' => 'KRS menunggu', 'value' => number_format(DB::table('semester_registrations')->where('status', 'submitted')->whereIn('student_id', DB::table('students')->select('id')->where('academic_advisor_id', $lecturerId))->count()), 'detail' => 'perlu direview', 'tone' => 'violet'],
                ['label' => 'Tugas terkumpul', 'value' => number_format(DB::table('lms_submissions')->whereIn('lms_assignment_id', DB::table('lms_assignments')->select('id')->whereIn('class_group_id', $classIds))->where('status', 'submitted')->count()), 'detail' => 'belum dinilai', 'tone' => 'emerald'],
            ];
        }
        if ($role === 'Calon Mahasiswa') {
            $application = DB::table('pmb_applications')->where('user_id', $request->user()->id)->first();
            return [
                ['label' => 'Status aplikasi', 'value' => $application?->status ?? 'Belum mulai', 'detail' => 'alur penerimaan', 'tone' => 'blue'],
                ['label' => 'Dokumen', 'value' => number_format($application ? DB::table('pmb_documents')->where('pmb_application_id', $application->id)->count() : 0), 'detail' => 'dokumen terunggah', 'tone' => 'amber'],
                ['label' => 'Invoice', 'value' => $application && DB::table('pmb_invoices')->where('pmb_application_id', $application->id)->where('status', 'paid')->exists() ? 'Lunas' : 'Menunggu', 'detail' => 'pembayaran PMB', 'tone' => 'violet'],
                ['label' => 'Hasil seleksi', 'value' => $application ? (DB::table('pmb_selection_results')->where('pmb_application_id', $application->id)->value('decision') ?? 'Belum tersedia') : '—', 'detail' => 'hasil resmi', 'tone' => 'emerald'],
            ];
        }
        return [
            ['label' => 'Mahasiswa aktif', 'value' => number_format(DB::table('students')->where('status', 'Aktif')->count()), 'detail' => 'data akademik terkini', 'tone' => 'blue'],
            ['label' => 'Tagihan berjalan', 'value' => 'Rp '.number_format((float) DB::table('billing_items')->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('amount - paid_amount')), 0, ',', '.'), 'detail' => 'piutang aktif', 'tone' => 'amber'],
            ['label' => 'Pendaftar PMB', 'value' => number_format(DB::table('pmb_applications')->count()), 'detail' => 'seluruh aplikasi', 'tone' => 'violet'],
            ['label' => 'Pembayaran hari ini', 'value' => 'Rp '.number_format((float) DB::table('payments')->whereDate('paid_at', now()->toDateString())->sum('amount'), 0, ',', '.'), 'detail' => 'diterima hari ini', 'tone' => 'emerald'],
        ];
    }
}
