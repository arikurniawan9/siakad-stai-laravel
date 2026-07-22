<?php

namespace App\Http\Controllers;

use App\Http\Requests\EdomQuestionnaireRequest;
use App\Http\Requests\EdomQuestionRequest;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\EdomQuestion;
use App\Models\EdomQuestionnaire;
use App\Models\EdomResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class EdomController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('edom.view'), 403);
        $filters = $request->validate(['selected' => ['nullable', 'integer', 'exists:edom_questionnaires,id']]);
        $user = $request->user();
        abort_unless(in_array($user->active_role, ['Admin', 'Prodi', 'Dosen', 'Mahasiswa'], true), 403);
        $questionnaires = EdomQuestionnaire::query()->with(['academicTerm:id,code,name,semester', 'questions'])->withCount('responses')->latest('starts_at')->get();
        $selectedId = isset($filters['selected']) ? (int) $filters['selected'] : $questionnaires->first()?->id;
        $selected = $selectedId ? $questionnaires->firstWhere('id', $selectedId) : null;
        $mode = $user->active_role === 'Mahasiswa' ? 'student' : ($user->active_role === 'Dosen' ? 'lecturer' : 'manager');
        $classes = collect(); $results = [];

        if ($selected && $mode === 'student') {
            $studentId = $user->student?->id ?? 0;
            $completed = EdomResponse::query()->where('edom_questionnaire_id', $selected->id)->where('student_id', $studentId)->pluck('class_group_id');
            $classes = ClassGroup::query()->with(['course:id,code,name,credits', 'lecturer:id,name,nidn'])
                ->where('academic_term_id', $selected->academic_term_id)
                ->whereHas('enrollments', fn (Builder $query) => $query->where('status', 'enrolled')->whereHas('registration', fn (Builder $query) => $query->where('student_id', $studentId)->where('status', 'approved')))
                ->get()->map(fn (ClassGroup $class) => [...$class->toArray(), 'completed' => $completed->contains($class->id)]);
        }
        if ($selected && $mode !== 'student') $results = $this->results($selected, $user->active_role === 'Dosen' ? ($user->lecturer?->id ?? 0) : null);

        return Inertia::render('Academic/Edom', [
            'mode' => $mode, 'questionnaires' => $questionnaires, 'selectedQuestionnaire' => $selected, 'classes' => $classes,
            'results' => $results, 'termOptions' => AcademicTerm::query()->latest('starts_on')->get(['id', 'code', 'name', 'semester', 'is_active']),
            'anonymityThreshold' => (int) config('siakad.edom_anonymity_threshold', 3),
            'abilities' => ['manage' => $mode === 'manager' && $user->can('edom.update')],
        ]);
    }

    public function storeQuestionnaire(EdomQuestionnaireRequest $request): RedirectResponse
    {
        $this->authorizeManager($request);
        $questionnaire = DB::transaction(function () use ($request): EdomQuestionnaire {
            $data = $request->safe()->except('include_default_questions');
            $questionnaire = EdomQuestionnaire::create($data);
            if ($request->boolean('include_default_questions')) $questionnaire->questions()->createMany($this->defaultQuestions());
            return $questionnaire;
        });
        $this->audit($request, 'questionnaire_created', 'edom_questionnaire', $questionnaire->id, ['title' => $questionnaire->title]);
        return to_route('academic.edom', ['selected' => $questionnaire->id])->with('success', 'Periode EDOM berhasil dibuat.');
    }

    public function updateQuestionnaire(EdomQuestionnaireRequest $request, EdomQuestionnaire $questionnaire): RedirectResponse
    {
        $this->authorizeManager($request); $questionnaire->update($request->safe()->except('include_default_questions'));
        $this->audit($request, 'questionnaire_updated', 'edom_questionnaire', $questionnaire->id, ['title' => $questionnaire->title]);
        return back()->with('success', 'Periode EDOM berhasil diperbarui.');
    }

    public function destroyQuestionnaire(Request $request, EdomQuestionnaire $questionnaire): RedirectResponse
    {
        $this->authorizeManager($request);
        if ($questionnaire->responses()->exists()) throw ValidationException::withMessages(['questionnaire' => 'Periode yang sudah memiliki respons tidak dapat dihapus. Nonaktifkan periode bila diperlukan.']);
        $questionnaire->delete(); $this->audit($request, 'questionnaire_deleted', 'edom_questionnaire', $questionnaire->id, []);
        return to_route('academic.edom')->with('success', 'Periode EDOM berhasil dihapus.');
    }

    public function storeQuestion(EdomQuestionRequest $request, EdomQuestionnaire $questionnaire): RedirectResponse
    {
        $this->authorizeManager($request); $question = $questionnaire->questions()->create($request->validated());
        $this->audit($request, 'question_created', 'edom_question', $question->id, ['questionnaire_id' => $questionnaire->id]);
        return back()->with('success', 'Butir pertanyaan berhasil ditambahkan.');
    }

    public function updateQuestion(EdomQuestionRequest $request, EdomQuestionnaire $questionnaire, EdomQuestion $question): RedirectResponse
    {
        $this->authorizeManager($request); $this->assertQuestion($questionnaire, $question);
        if ($question->answers()->exists() && $question->type !== $request->validated('type')) throw ValidationException::withMessages(['type' => 'Tipe pertanyaan yang sudah dijawab tidak dapat diubah.']);
        $question->update($request->validated()); $this->audit($request, 'question_updated', 'edom_question', $question->id, []);
        return back()->with('success', 'Butir pertanyaan berhasil diperbarui.');
    }

    public function destroyQuestion(Request $request, EdomQuestionnaire $questionnaire, EdomQuestion $question): RedirectResponse
    {
        $this->authorizeManager($request); $this->assertQuestion($questionnaire, $question);
        if ($question->answers()->exists()) throw ValidationException::withMessages(['question' => 'Pertanyaan yang sudah dijawab tidak dapat dihapus.']);
        $question->delete(); $this->audit($request, 'question_deleted', 'edom_question', $question->id, []);
        return back()->with('success', 'Butir pertanyaan berhasil dihapus.');
    }

    public function submit(Request $request, EdomQuestionnaire $questionnaire, ClassGroup $classGroup): RedirectResponse
    {
        abort_unless($request->user()->active_role === 'Mahasiswa' && $request->user()->can('edom.create'), 403);
        if (! $questionnaire->isOpen()) throw ValidationException::withMessages(['questionnaire' => 'Periode evaluasi belum dibuka atau sudah berakhir.']);
        abort_unless((int) $classGroup->academic_term_id === (int) $questionnaire->academic_term_id, 404);
        $studentId = $request->user()->student?->id ?? 0;
        abort_unless($classGroup->enrollments()->where('status', 'enrolled')->whereHas('registration', fn (Builder $query) => $query->where('student_id', $studentId)->where('status', 'approved'))->exists(), 403);
        if (EdomResponse::query()->where(['edom_questionnaire_id' => $questionnaire->id, 'student_id' => $studentId, 'class_group_id' => $classGroup->id])->exists()) throw ValidationException::withMessages(['questionnaire' => 'Evaluasi untuk kelas ini sudah pernah dikirim.']);
        $questions = $questionnaire->questions()->get();
        if ($questions->isEmpty()) throw ValidationException::withMessages(['questionnaire' => 'Kuesioner belum memiliki pertanyaan.']);
        $payload = $request->validate(['answers' => ['required', 'array'], 'answers.*.rating' => ['nullable', 'integer', 'between:1,5'], 'answers.*.essay_answer' => ['nullable', 'string', 'max:5000'], 'suggestion' => ['nullable', 'string', 'max:5000']]);
        $rows = []; $ratings = [];
        foreach ($questions as $question) {
            $answer = $payload['answers'][(string) $question->id] ?? $payload['answers'][$question->id] ?? [];
            $value = $question->type === 'rating' ? ($answer['rating'] ?? null) : trim((string) ($answer['essay_answer'] ?? ''));
            if ($question->is_required && blank($value)) throw ValidationException::withMessages(["answers.{$question->id}" => 'Pertanyaan ini wajib dijawab.']);
            if ($question->type === 'rating' && filled($value)) $ratings[] = (int) $value;
            $rows[] = ['edom_question_id' => $question->id, 'rating' => $question->type === 'rating' ? $value : null, 'essay_answer' => $question->type === 'essay' ? ($value ?: null) : null, 'created_at' => now(), 'updated_at' => now()];
        }
        $response = DB::transaction(function () use ($questionnaire, $studentId, $classGroup, $payload, $ratings, $rows): EdomResponse {
            $response = EdomResponse::create(['edom_questionnaire_id' => $questionnaire->id, 'student_id' => $studentId, 'class_group_id' => $classGroup->id, 'average_score' => $ratings ? round(array_sum($ratings) / count($ratings), 2) : 0, 'suggestion' => $payload['suggestion'] ?? null, 'submitted_at' => now()]);
            $response->answers()->createMany($rows); return $response;
        });
        $this->audit($request, 'response_submitted', 'edom_response', $response->id, ['questionnaire_id' => $questionnaire->id, 'class_group_id' => $classGroup->id]);
        return back()->with('success', 'Evaluasi berhasil dikirim secara anonim. Terima kasih atas kontribusi Anda.');
    }

    private function results(EdomQuestionnaire $questionnaire, ?int $lecturerId): array
    {
        $query = EdomResponse::query()->with(['classGroup.course:id,code,name', 'classGroup.lecturer:id,name,nidn', 'answers.question:id,category,question,type'])->where('edom_questionnaire_id', $questionnaire->id)
            ->when($lecturerId !== null, fn (Builder $query) => $query->whereHas('classGroup', fn (Builder $query) => $query->where('lecturer_id', $lecturerId)));
        $threshold = (int) config('siakad.edom_anonymity_threshold', 3);
        return $query->get()->groupBy('class_group_id')->map(function ($responses) use ($threshold): array {
            $first = $responses->first(); $count = $responses->count();
            $questionScores = $responses->flatMap->answers->filter(fn ($answer) => $answer->rating !== null)->groupBy('edom_question_id')->map(function ($answers): array { $question = $answers->first()->question; return ['question_id' => $question->id, 'category' => $question->category, 'question' => $question->question, 'average' => round($answers->avg('rating'), 2)]; })->values();
            return ['class_group' => $first->classGroup, 'response_count' => $count, 'average_score' => round($responses->avg('average_score'), 2), 'question_scores' => $questionScores, 'suggestions' => $count >= $threshold ? $responses->pluck('suggestion')->filter()->values() : [], 'privacy_protected' => $count < $threshold];
        })->values()->all();
    }

    private function authorizeManager(Request $request): void { abort_unless(in_array($request->user()->active_role, ['Admin', 'Prodi'], true) && $request->user()->can('edom.update'), 403); }
    private function assertQuestion(EdomQuestionnaire $questionnaire, EdomQuestion $question): void { abort_unless((int) $question->edom_questionnaire_id === (int) $questionnaire->id, 404); }
    private function audit(Request $request, string $action, string $type, int $id, array $data): void { DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'edom', 'action' => $action, 'record_type' => $type, 'record_id' => (string) $id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]); }
    private function defaultQuestions(): array
    {
        return [
            ['category' => 'Perencanaan', 'question' => 'Dosen menyampaikan tujuan dan rencana pembelajaran dengan jelas.', 'type' => 'rating', 'sort_order' => 10, 'is_required' => true],
            ['category' => 'Penguasaan materi', 'question' => 'Dosen menguasai dan menjelaskan materi secara sistematis.', 'type' => 'rating', 'sort_order' => 20, 'is_required' => true],
            ['category' => 'Metode pembelajaran', 'question' => 'Metode pembelajaran mendorong mahasiswa aktif dan berpikir kritis.', 'type' => 'rating', 'sort_order' => 30, 'is_required' => true],
            ['category' => 'Interaksi', 'question' => 'Dosen terbuka terhadap pertanyaan dan memberikan respons yang membantu.', 'type' => 'rating', 'sort_order' => 40, 'is_required' => true],
            ['category' => 'Penilaian', 'question' => 'Penilaian dilakukan secara transparan dan sesuai materi pembelajaran.', 'type' => 'rating', 'sort_order' => 50, 'is_required' => true],
            ['category' => 'Kedisiplinan', 'question' => 'Dosen melaksanakan perkuliahan secara disiplin dan tepat waktu.', 'type' => 'rating', 'sort_order' => 60, 'is_required' => true],
            ['category' => 'Refleksi', 'question' => 'Hal apa yang paling membantu Anda dalam perkuliahan ini?', 'type' => 'essay', 'sort_order' => 70, 'is_required' => false],
            ['category' => 'Refleksi', 'question' => 'Apa yang sebaiknya ditingkatkan pada perkuliahan berikutnya?', 'type' => 'essay', 'sort_order' => 80, 'is_required' => false],
        ];
    }
}
