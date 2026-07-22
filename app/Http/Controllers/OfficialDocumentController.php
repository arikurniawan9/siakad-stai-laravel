<?php

namespace App\Http\Controllers;

use App\Domain\Documents\OfficialDocumentService;
use App\Models\BillingItem;
use App\Models\OfficialDocument;
use App\Models\Payment;
use App\Models\SemesterRegistration;
use App\Models\Student;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

final class OfficialDocumentController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('documents.view'), 403);
        $user = $request->user();
        abort_unless(in_array($user->active_role, ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Keuangan', 'Bendahara'], true), 403);
        $filters = $request->validate(['student_id' => ['nullable', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')]]);
        $students = Student::query()->with(['user:id,name', 'program:id,code,name'])->whereNotNull('nim')
            ->when($user->active_role === 'Dosen', fn (Builder $query) => $query->where('academic_advisor_id', $user->lecturer?->id ?? 0));
        $student = $user->active_role === 'Mahasiswa' ? $user->student : (isset($filters['student_id']) ? (clone $students)->find($filters['student_id']) : (clone $students)->orderBy('nim')->first());
        if ($user->active_role === 'Mahasiswa') abort_unless($student, 403);
        if (isset($filters['student_id']) && ! $student) abort(403);
        $academicAccess = in_array($user->active_role, ['Admin', 'Prodi', 'Dosen', 'Mahasiswa'], true);
        $financeAccess = in_array($user->active_role, ['Admin', 'Keuangan', 'Bendahara', 'Mahasiswa'], true);
        $visibleTypes = [...($academicAccess ? ['krs', 'khs', 'transcript'] : []), ...($financeAccess ? ['invoice', 'receipt'] : [])];

        $registrations = $student && $academicAccess ? SemesterRegistration::query()->with(['academicTerm:id,code,name,semester'])->withCount(['enrollments' => fn ($query) => $query->where('status', 'enrolled'), 'enrollments as published_grades_count' => fn ($query) => $query->where('status', 'enrolled')->whereIn('grade_status', ['published', 'finalized'])])->where('student_id', $student->id)->where('status', 'approved')->latest('academic_term_id')->get() : collect();
        $bills = $student && $financeAccess ? BillingItem::query()->with('academicTerm:id,code,name')->where('student_id', $student->id)->latest('id')->get() : collect();
        $payments = $student && $financeAccess ? Payment::query()->with('allocations.billingItem:id,invoice_number,description')->where('student_id', $student->id)->whereNotIn('status', ['failed', 'reversed'])->latest('paid_at')->get() : collect();
        $issued = $student ? OfficialDocument::query()->with(['issuer:id,name', 'revoker:id,name'])->where('student_id', $student->id)->whereIn('type', $visibleTypes)->latest('issued_at')->get() : collect();

        return Inertia::render('Documents/Index', [
            'mode' => $user->active_role === 'Mahasiswa' ? 'student' : 'manager', 'selectedStudent' => $student?->loadMissing(['user:id,name,email', 'program:id,code,name,degree']),
            'studentOptions' => $user->active_role === 'Mahasiswa' ? [] : $students->orderBy('nim')->get(['id', 'user_id', 'program_id', 'nim']),
            'registrations' => $registrations, 'bills' => $bills, 'payments' => $payments, 'issuedDocuments' => $issued,
            'access' => ['academic' => $academicAccess, 'finance' => $financeAccess, 'issue' => $user->can('documents.create'), 'revoke' => $user->active_role !== 'Mahasiswa' && $user->can('documents.create')],
        ]);
    }

    public function issue(Request $request, string $type, int $sourceId, OfficialDocumentService $service): RedirectResponse
    {
        $document = $service->issue($type, $sourceId, $request->user());
        $this->audit($request, 'document_issued', $document, ['type' => $document->type, 'document_number' => $document->document_number]);

        return redirect()->route('documents.show', $document)->with('success', 'Dokumen resmi berhasil diterbitkan dan dapat diverifikasi.');
    }

    public function show(Request $request, OfficialDocument $document, OfficialDocumentService $service): View
    {
        $service->authorize($request->user(), $document->type, $document->student()->firstOrFail());

        return view('documents.official', $this->viewData($document, false));
    }

    public function pdf(Request $request, OfficialDocument $document, OfficialDocumentService $service): HttpResponse
    {
        $service->authorize($request->user(), $document->type, $document->student()->firstOrFail());
        $options = new Options(); $options->set('isRemoteEnabled', false); $options->set('isHtml5ParserEnabled', true); $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options); $dompdf->loadHtml(view('documents.official', $this->viewData($document, true))->render()); $dompdf->setPaper('A4', 'portrait'); $dompdf->render();

        return response($dompdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.str_replace('/', '-', $document->document_number).'.pdf"']);
    }

    public function revoke(Request $request, OfficialDocument $document, OfficialDocumentService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $service->revoke($document, $request->user(), $data['reason']); $this->audit($request, 'document_revoked', $document, ['reason' => $data['reason']]);

        return back()->with('success', 'Dokumen berhasil dicabut; halaman verifikasi telah diperbarui.');
    }

    public function verify(string $verificationCode): View
    {
        $document = OfficialDocument::query()->with(['issuer:id,name', 'revoker:id,name'])->where('verification_code', $verificationCode)->firstOrFail();
        $hash = hash('sha256', json_encode($document->snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $integrityValid = hash_equals($document->content_hash, $hash);

        return view('documents.verify', ['document' => $document, 'integrityValid' => $integrityValid, 'valid' => $integrityValid && ! $document->revoked_at, 'typeLabel' => $this->typeLabel($document->type)]);
    }

    private function viewData(OfficialDocument $document, bool $pdf): array
    {
        $document->loadMissing(['issuer:id,name', 'revoker:id,name']); $verificationUrl = route('documents.verify', $document->verification_code);
        $renderer = new ImageRenderer(new RendererStyle(126, 1), new SvgImageBackEnd()); $svg = (new Writer($renderer))->writeString($verificationUrl);
        $logoPath = public_path('img/logostai.png'); $logo = is_file($logoPath) ? 'data:image/'.pathinfo($logoPath, PATHINFO_EXTENSION).';base64,'.base64_encode((string) file_get_contents($logoPath)) : null;
        return ['document' => $document, 'snapshot' => $document->snapshot, 'verificationUrl' => $verificationUrl, 'qrCode' => 'data:image/svg+xml;base64,'.base64_encode($svg), 'logo' => $logo, 'pdf' => $pdf, 'typeLabel' => $this->typeLabel($document->type)];
    }

    private function typeLabel(string $type): string
    {
        return match ($type) { 'krs' => 'Kartu Rencana Studi', 'khs' => 'Kartu Hasil Studi', 'transcript' => 'Transkrip Nilai Akademik', 'invoice' => 'Tagihan Pendidikan', 'receipt' => 'Kwitansi Pembayaran', default => 'Dokumen Resmi' };
    }

    private function audit(Request $request, string $action, OfficialDocument $document, array $data): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'documents', 'action' => $action, 'record_type' => 'official_document', 'record_id' => (string) $document->id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
