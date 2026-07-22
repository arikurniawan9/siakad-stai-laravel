<?php

namespace App\Http\Controllers;

use App\Http\Requests\LmsAssignmentRequest;
use App\Http\Requests\LmsForumTopicRequest;
use App\Http\Requests\LmsGradeSubmissionRequest;
use App\Http\Requests\LmsMaterialRequest;
use App\Http\Requests\LmsSubmissionRequest;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\LmsAssignment;
use App\Models\LmsForumComment;
use App\Models\LmsForumTopic;
use App\Models\LmsMaterial;
use App\Models\LmsSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\NotificationService;

final class LmsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('lms.view'), 403);
        $filters = $request->validate(['academic_term_id' => ['nullable', 'integer', 'exists:academic_terms,id'], 'q' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'integer', 'exists:class_groups,id']]);
        $user = $request->user();
        $search = trim((string) ($filters['q'] ?? ''));
        $classes = ClassGroup::query()
            ->with(['course:id,program_id,code,name,credits', 'course.program:id,code,name', 'academicTerm:id,code,name,semester,is_active', 'lecturer:id,name,nidn'])
            ->withCount(['materials', 'assignments', 'forumTopics'])
            ->when(isset($filters['academic_term_id']), fn (Builder $query) => $query->where('academic_term_id', $filters['academic_term_id']))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhereHas('course', fn (Builder $query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))))
            ->when($user->active_role === 'Dosen', fn (Builder $query) => $query->where('lecturer_id', $user->lecturer?->id ?? 0))
            ->when($user->active_role === 'Mahasiswa', fn (Builder $query) => $query->whereHas('enrollments', fn (Builder $query) => $query->where('status', 'enrolled')->whereHas('registration', fn (Builder $query) => $query->where('student_id', $user->student?->id ?? 0)->where('status', 'approved'))));

        abort_unless(in_array($user->active_role, ['Admin', 'Prodi', 'Dosen', 'Mahasiswa'], true), 403);
        $selectedId = isset($filters['selected']) ? (clone $classes)->whereKey($filters['selected'])->value('id') : (clone $classes)->latest('academic_term_id')->value('id');
        $selected = $selectedId ? ClassGroup::query()->with(['course:id,program_id,code,name,credits', 'course.program:id,code,name', 'academicTerm:id,code,name,semester,is_active', 'lecturer:id,name,nidn'])->find($selectedId) : null;
        if ($selected) {
            Gate::authorize('viewLms', $selected);
            $isStudent = $user->active_role === 'Mahasiswa';
            $selected->setRelation('materials', LmsMaterial::query()->with('creator:id,name')->where('class_group_id', $selected->id)->when($isStudent, fn ($query) => $query->where('is_published', true))->latest('published_at')->latest()->get());
            $assignmentQuery = LmsAssignment::query()->with('creator:id,name')->where('class_group_id', $selected->id)->when($isStudent, fn ($query) => $query->where('is_published', true));
            if ($isStudent) {
                $enrollmentId = $this->studentEnrollmentId($request, $selected);
                $assignmentQuery->with(['submissions' => fn ($query) => $query->where('course_enrollment_id', $enrollmentId)->with('grader:id,name')]);
            } else {
                $assignmentQuery->with(['submissions.enrollment.registration.student.user:id,name,email', 'submissions.enrollment.registration.student:id,user_id,nim', 'submissions.grader:id,name'])->withCount('submissions');
            }
            $selected->setRelation('assignments', $assignmentQuery->orderBy('due_at')->get());
            $selected->setRelation('forumTopics', LmsForumTopic::query()->with(['user:id,name,active_role', 'comments.user:id,name,active_role'])->where('class_group_id', $selected->id)->orderByDesc('is_pinned')->latest()->get());
        }

        return Inertia::render('Academic/Lms', [
            'filters' => ['academic_term_id' => (string) ($filters['academic_term_id'] ?? ''), 'q' => $search, 'selected' => $selectedId],
            'termOptions' => AcademicTerm::query()->latest('starts_on')->get(['id', 'name', 'code', 'semester', 'is_active']),
            'classGroups' => $classes->latest('academic_term_id')->paginate(12)->withQueryString(), 'selectedClass' => $selected,
            'mode' => $user->active_role === 'Mahasiswa' ? 'student' : 'manager',
            'abilities' => ['manage' => $selected ? $user->can('manageLms', $selected) : false, 'discuss' => (bool) $selected],
        ]);
    }

    public function storeMaterial(LmsMaterialRequest $request, ClassGroup $classGroup): RedirectResponse
    {
        Gate::authorize('manageLms', $classGroup);
        $data = $request->safe()->except(['attachment', 'remove_attachment']);
        $material = new LmsMaterial([...$data, 'created_by' => $request->user()->id, 'published_at' => $data['is_published'] ? now() : null]);
        $material->class_group_id = $classGroup->id;
        $this->storeAttachment($request, $material, 'lms/materials');
        $material->save();
        $this->audit($request, 'material_created', 'lms_material', $material->id, ['class_group_id' => $classGroup->id, 'title' => $material->title]);
        return back()->with('success', 'Materi pembelajaran berhasil ditambahkan.');
    }

    public function updateMaterial(LmsMaterialRequest $request, ClassGroup $classGroup, LmsMaterial $material): RedirectResponse
    {
        $this->assertChild($classGroup, $material);
        Gate::authorize('manageLms', $classGroup);
        $data = $request->safe()->except(['attachment', 'remove_attachment']);
        if ($request->boolean('remove_attachment') || $request->hasFile('attachment')) $this->deleteAttachment($material);
        $material->fill([...$data, 'published_at' => $data['is_published'] ? ($material->published_at ?? now()) : null]);
        $this->storeAttachment($request, $material, 'lms/materials');
        $material->save();
        $this->audit($request, 'material_updated', 'lms_material', $material->id, ['title' => $material->title]);
        return back()->with('success', 'Materi pembelajaran berhasil diperbarui.');
    }

    public function destroyMaterial(Request $request, ClassGroup $classGroup, LmsMaterial $material): RedirectResponse
    {
        $this->assertChild($classGroup, $material); Gate::authorize('manageLms', $classGroup);
        $this->deleteAttachment($material); $material->delete();
        $this->audit($request, 'material_deleted', 'lms_material', $material->id, ['title' => $material->title]);
        return back()->with('success', 'Materi berhasil dihapus.');
    }

    public function materialAttachment(Request $request, ClassGroup $classGroup, LmsMaterial $material): StreamedResponse
    {
        $this->assertChild($classGroup, $material); Gate::authorize('viewLms', $classGroup);
        abort_if($request->user()->active_role === 'Mahasiswa' && ! $material->is_published, 404);
        return $this->download($material);
    }

    public function storeAssignment(LmsAssignmentRequest $request, ClassGroup $classGroup): RedirectResponse
    {
        Gate::authorize('manageLms', $classGroup);
        $data = $request->safe()->except(['attachment', 'remove_attachment']);
        $assignment = new LmsAssignment([...$data, 'created_by' => $request->user()->id, 'published_at' => $data['is_published'] ? now() : null]);
        $assignment->class_group_id = $classGroup->id;
        $this->storeAttachment($request, $assignment, 'lms/assignments'); $assignment->save();
        if ($assignment->is_published) app(NotificationService::class)->classStudents($classGroup, 'lms', 'Tugas baru tersedia', $assignment->title.' telah diterbitkan. Tenggat '.$assignment->due_at->format('d M Y H:i').'.', '/academic/lms?selected='.$classGroup->id);
        $this->audit($request, 'assignment_created', 'lms_assignment', $assignment->id, ['class_group_id' => $classGroup->id, 'title' => $assignment->title]);
        return back()->with('success', 'Tugas berhasil diterbitkan.');
    }

    public function updateAssignment(LmsAssignmentRequest $request, ClassGroup $classGroup, LmsAssignment $assignment): RedirectResponse
    {
        $this->assertChild($classGroup, $assignment); Gate::authorize('manageLms', $classGroup);
        $data = $request->safe()->except(['attachment', 'remove_attachment']);
        if ($request->boolean('remove_attachment') || $request->hasFile('attachment')) $this->deleteAttachment($assignment);
        $assignment->fill([...$data, 'published_at' => $data['is_published'] ? ($assignment->published_at ?? now()) : null]);
        $this->storeAttachment($request, $assignment, 'lms/assignments'); $assignment->save();
        $this->audit($request, 'assignment_updated', 'lms_assignment', $assignment->id, ['title' => $assignment->title]);
        return back()->with('success', 'Tugas berhasil diperbarui.');
    }

    public function destroyAssignment(Request $request, ClassGroup $classGroup, LmsAssignment $assignment): RedirectResponse
    {
        $this->assertChild($classGroup, $assignment); Gate::authorize('manageLms', $classGroup);
        foreach ($assignment->submissions as $submission) $this->deleteAttachment($submission);
        $this->deleteAttachment($assignment); $assignment->delete();
        $this->audit($request, 'assignment_deleted', 'lms_assignment', $assignment->id, ['title' => $assignment->title]);
        return back()->with('success', 'Tugas berhasil dihapus.');
    }

    public function assignmentAttachment(Request $request, ClassGroup $classGroup, LmsAssignment $assignment): StreamedResponse
    {
        $this->assertChild($classGroup, $assignment); Gate::authorize('viewLms', $classGroup);
        abort_if($request->user()->active_role === 'Mahasiswa' && ! $assignment->is_published, 404);
        return $this->download($assignment);
    }

    public function submit(LmsSubmissionRequest $request, ClassGroup $classGroup, LmsAssignment $assignment): RedirectResponse
    {
        $this->assertChild($classGroup, $assignment); Gate::authorize('viewLms', $classGroup);
        abort_unless($request->user()->active_role === 'Mahasiswa' && $assignment->is_published, 403);
        $enrollmentId = $this->studentEnrollmentId($request, $classGroup);
        $submission = LmsSubmission::firstOrNew(['lms_assignment_id' => $assignment->id, 'course_enrollment_id' => $enrollmentId]);
        if ($submission->exists && $submission->status === 'graded') throw ValidationException::withMessages(['submission' => 'Tugas yang sudah dinilai tidak dapat dikirim ulang.']);
        if ($submission->exists && $request->hasFile('attachment')) $this->deleteAttachment($submission);
        $submission->fill([...$request->safe()->except('attachment'), 'status' => 'submitted', 'submitted_at' => now(), 'score' => null, 'feedback' => null, 'graded_by' => null, 'graded_at' => null]);
        $this->storeAttachment($request, $submission, 'lms/submissions'); $submission->save();
        $this->audit($request, 'assignment_submitted', 'lms_submission', $submission->id, ['assignment_id' => $assignment->id, 'late' => now()->isAfter($assignment->due_at)]);
        return back()->with('success', now()->isAfter($assignment->due_at) ? 'Tugas berhasil dikirim dan tercatat terlambat.' : 'Tugas berhasil dikirim.');
    }

    public function grade(LmsGradeSubmissionRequest $request, ClassGroup $classGroup, LmsAssignment $assignment, LmsSubmission $submission): RedirectResponse
    {
        $this->assertChild($classGroup, $assignment); abort_unless((int) $submission->lms_assignment_id === (int) $assignment->id, 404); Gate::authorize('manageLms', $classGroup);
        if ((float) $request->validated('score') > (float) $assignment->max_points) throw ValidationException::withMessages(['score' => "Nilai maksimal adalah {$assignment->max_points}."]);
        $submission->update([...$request->validated(), 'status' => 'graded', 'graded_by' => $request->user()->id, 'graded_at' => now()]);
        $this->audit($request, 'submission_graded', 'lms_submission', $submission->id, ['score' => $submission->score]);
        return back()->with('success', 'Nilai dan umpan balik berhasil disimpan.');
    }

    public function submissionAttachment(Request $request, ClassGroup $classGroup, LmsAssignment $assignment, LmsSubmission $submission): StreamedResponse
    {
        $this->assertChild($classGroup, $assignment); abort_unless((int) $submission->lms_assignment_id === (int) $assignment->id, 404); Gate::authorize('viewLms', $classGroup);
        if ($request->user()->active_role === 'Mahasiswa') abort_unless((int) $submission->course_enrollment_id === $this->studentEnrollmentId($request, $classGroup), 403);
        return $this->download($submission);
    }

    public function storeTopic(LmsForumTopicRequest $request, ClassGroup $classGroup): RedirectResponse
    {
        Gate::authorize('viewLms', $classGroup);
        $topic = $classGroup->forumTopics()->create([...$request->validated(), 'user_id' => $request->user()->id]);
        $this->audit($request, 'forum_topic_created', 'lms_forum_topic', $topic->id, ['class_group_id' => $classGroup->id]);
        return back()->with('success', 'Topik diskusi berhasil dibuat.');
    }

    public function moderateTopic(Request $request, ClassGroup $classGroup, LmsForumTopic $topic): RedirectResponse
    {
        $this->assertChild($classGroup, $topic); Gate::authorize('manageLms', $classGroup);
        $data = $request->validate(['is_pinned' => ['required', 'boolean'], 'is_locked' => ['required', 'boolean']]); $topic->update($data);
        $this->audit($request, 'forum_topic_moderated', 'lms_forum_topic', $topic->id, $data);
        return back()->with('success', 'Pengaturan topik berhasil diperbarui.');
    }

    public function destroyTopic(Request $request, ClassGroup $classGroup, LmsForumTopic $topic): RedirectResponse
    {
        $this->assertChild($classGroup, $topic); Gate::authorize('viewLms', $classGroup);
        abort_unless($request->user()->can('manageLms', $classGroup) || (int) $topic->user_id === (int) $request->user()->id, 403);
        $topic->delete(); $this->audit($request, 'forum_topic_deleted', 'lms_forum_topic', $topic->id, []);
        return back()->with('success', 'Topik diskusi dihapus.');
    }

    public function storeComment(Request $request, ClassGroup $classGroup, LmsForumTopic $topic): RedirectResponse
    {
        $this->assertChild($classGroup, $topic); Gate::authorize('viewLms', $classGroup); abort_if($topic->is_locked, 422, 'Topik diskusi sudah dikunci.');
        $data = $request->validate(['content' => ['required', 'string', 'max:10000']]);
        $comment = $topic->comments()->create([...$data, 'user_id' => $request->user()->id]);
        $this->audit($request, 'forum_comment_created', 'lms_forum_comment', $comment->id, ['topic_id' => $topic->id]);
        return back()->with('success', 'Tanggapan berhasil dikirim.');
    }

    public function destroyComment(Request $request, ClassGroup $classGroup, LmsForumTopic $topic, LmsForumComment $comment): RedirectResponse
    {
        $this->assertChild($classGroup, $topic); abort_unless((int) $comment->lms_forum_topic_id === (int) $topic->id, 404); Gate::authorize('viewLms', $classGroup);
        abort_unless($request->user()->can('manageLms', $classGroup) || (int) $comment->user_id === (int) $request->user()->id, 403);
        $comment->delete(); $this->audit($request, 'forum_comment_deleted', 'lms_forum_comment', $comment->id, []);
        return back()->with('success', 'Tanggapan dihapus.');
    }

    private function studentEnrollmentId(Request $request, ClassGroup $classGroup): int
    {
        $id = $classGroup->enrollments()->where('status', 'enrolled')->whereHas('registration', fn (Builder $query) => $query->where('student_id', $request->user()->student?->id ?? 0)->where('status', 'approved'))->value('id');
        abort_unless($id, 403); return (int) $id;
    }

    private function assertChild(ClassGroup $classGroup, object $model): void { abort_unless((int) $model->class_group_id === (int) $classGroup->id, 404); }

    private function storeAttachment(Request $request, object $model, string $directory): void
    {
        if (! $request->hasFile('attachment')) return;
        $file = $request->file('attachment'); $model->attachment_path = $file->store($directory, 'local');
        $model->attachment_name = $file->getClientOriginalName(); $model->attachment_mime = $file->getMimeType(); $model->attachment_size = $file->getSize();
    }

    private function deleteAttachment(object $model): void
    {
        if ($model->attachment_path) Storage::disk('local')->delete($model->attachment_path);
        $model->attachment_path = $model->attachment_name = $model->attachment_mime = $model->attachment_size = null;
    }

    private function download(object $model): StreamedResponse
    {
        abort_unless($model->attachment_path && Storage::disk('local')->exists($model->attachment_path), 404);
        return Storage::disk('local')->download($model->attachment_path, $model->attachment_name, ['Content-Type' => $model->attachment_mime ?? 'application/octet-stream']);
    }

    private function audit(Request $request, string $action, string $type, int $id, array $data): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'lms', 'action' => $action, 'record_type' => $type, 'record_id' => (string) $id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
