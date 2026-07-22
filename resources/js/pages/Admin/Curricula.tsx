import { Head, router, useForm } from '@inertiajs/react';
import {
  Archive,
  BookCheck,
  BookOpenCheck,
  Check,
  ChevronLeft,
  ChevronRight,
  GitBranch,
  Pencil,
  Plus,
  RotateCcw,
  Search,
  Trash2,
  X,
} from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { DashboardLayout } from '@/layouts/DashboardLayout';

type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
type Program = { id: number; name: string; code: string };
type Term = { id: number; name: string; code: string };
type Course = { id: number; program_id: number; code: string; name: string; credits: number; type?: string };
type CurriculumCourse = { id: number; course_id: number; semester: number; credits: number; is_required: boolean; course: Course };
type Curriculum = {
  id: number;
  program_id: number;
  effective_term_id: number | null;
  name: string;
  code: string;
  target_credits: number;
  description: string | null;
  is_active: boolean;
  deleted_at: string | null;
  program: Program;
  effective_term: Term | null;
  curriculum_courses_count?: number;
  assigned_credits?: number | string | null;
  curriculum_courses?: CurriculumCourse[];
};
type Prerequisite = { id: number; course: Pick<Course, 'id' | 'code' | 'name'>; prerequisite_course: Pick<Course, 'id' | 'code' | 'name'>; minimum_grade: string };
type Filters = { q: string; program_id: string; status: 'available' | 'archived'; selected: number | null };
type Props = {
  filters: Filters;
  curricula: Page<Curriculum>;
  selectedCurriculum: Curriculum | null;
  programOptions: Program[];
  termOptions: Term[];
  courseOptions: Course[];
  prerequisites: Prerequisite[];
  summary: { available: number; active: number; mapped_courses: number; prerequisites: number; archived: number };
  abilities: { create: boolean; update: boolean; delete: boolean };
};
type CurriculumForm = { program_id: string; effective_term_id: string; name: string; code: string; target_credits: number; description: string; is_active: boolean };
type CourseForm = { course_id: string; semester: number; credits: number; is_required: boolean };
type PrerequisiteForm = { course_id: string; prerequisite_course_id: string; minimum_grade: string };

const initialCurriculum: CurriculumForm = { program_id: '', effective_term_id: '', name: '', code: '', target_credits: 144, description: '', is_active: false };
const initialCourse: CourseForm = { course_id: '', semester: 1, credits: 3, is_required: true };
const initialPrerequisite: PrerequisiteForm = { course_id: '', prerequisite_course_id: '', minimum_grade: 'C' };

export default function Curricula({ filters, curricula, selectedCurriculum, programOptions, termOptions, courseOptions, prerequisites, summary, abilities }: Props) {
  const [filterData, setFilterData] = useState(filters);
  const [editingCurriculum, setEditingCurriculum] = useState<number | null>(null);
  const [editingCourse, setEditingCourse] = useState<number | null>(null);
  const curriculumForm = useForm<CurriculumForm>(initialCurriculum);
  const courseForm = useForm<CourseForm>(initialCourse);
  const prerequisiteForm = useForm<PrerequisiteForm>(initialPrerequisite);
  const isArchived = Boolean(selectedCurriculum?.deleted_at);

  function submitCurriculum(event: FormEvent) {
    event.preventDefault();
    const options = { preserveScroll: true, onSuccess: resetCurriculumForm };
    if (editingCurriculum) {
      curriculumForm.patch(`/admin/curricula/${editingCurriculum}`, options);
      return;
    }
    curriculumForm.post('/admin/curricula', options);
  }

  function editCurriculum(item: Curriculum) {
    setEditingCurriculum(item.id);
    curriculumForm.setData({
      program_id: String(item.program_id),
      effective_term_id: item.effective_term_id ? String(item.effective_term_id) : '',
      name: item.name,
      code: item.code,
      target_credits: item.target_credits,
      description: item.description ?? '',
      is_active: item.is_active,
    });
    curriculumForm.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function resetCurriculumForm() {
    setEditingCurriculum(null);
    curriculumForm.reset();
    curriculumForm.clearErrors();
  }

  function submitCourse(event: FormEvent) {
    event.preventDefault();
    if (!selectedCurriculum) return;
    const options = { preserveScroll: true, onSuccess: resetCourseForm };
    if (editingCourse) {
      courseForm.patch(`/admin/curricula/${selectedCurriculum.id}/courses/${editingCourse}`, options);
      return;
    }
    courseForm.post(`/admin/curricula/${selectedCurriculum.id}/courses`, options);
  }

  function editCourse(item: CurriculumCourse) {
    setEditingCourse(item.id);
    courseForm.setData({ course_id: String(item.course_id), semester: item.semester, credits: item.credits, is_required: item.is_required });
    courseForm.clearErrors();
  }

  function resetCourseForm() {
    setEditingCourse(null);
    courseForm.reset();
    courseForm.clearErrors();
  }

  function submitPrerequisite(event: FormEvent) {
    event.preventDefault();
    prerequisiteForm.post('/admin/course-prerequisites', { preserveScroll: true, onSuccess: () => prerequisiteForm.reset() });
  }

  function applyFilters(event: FormEvent) {
    event.preventDefault();
    router.get('/admin/curricula', { ...filterData, selected: undefined }, { preserveState: true, replace: true });
  }

  function selectCurriculum(id: number) {
    router.get('/admin/curricula', { q: filterData.q, program_id: filterData.program_id, status: filterData.status, selected: id }, { preserveState: true, replace: true });
  }

  function paginate(page: number) {
    router.get('/admin/curricula', { ...filterData, page }, { preserveState: true, replace: true });
  }

  function archive(item: Curriculum) {
    if (window.confirm(`Arsipkan kurikulum ${item.name}?`)) router.delete(`/admin/curricula/${item.id}`);
  }

  const assignedCredits = selectedCurriculum?.curriculum_courses?.reduce((sum, item) => sum + item.credits, 0) ?? 0;
  const progress = selectedCurriculum ? Math.min(100, Math.round((assignedCredits / selectedCurriculum.target_credits) * 100)) : 0;

  return (
    <DashboardLayout>
      <Head title="Kurikulum" />
      <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
        <div><p className="text-sm font-medium text-teal-600">Struktur akademik</p><h1 className="mt-2 text-3xl font-semibold tracking-[-.04em] text-slate-950">Kurikulum.</h1><p className="mt-2 text-sm text-slate-500">Kelola struktur semester, beban SKS, dan prasyarat mata kuliah per program studi.</p></div>
        <div className="flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm font-semibold text-slate-600 ring-1 ring-slate-200"><Archive size={16} className="text-teal-600" /> {summary.archived} kurikulum diarsipkan</div>
      </div>

      <div className="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Summary icon={<BookOpenCheck size={18} />} label="Kurikulum tersedia" value={summary.available} />
        <Summary icon={<Check size={18} />} label="Kurikulum aktif" value={summary.active} />
        <Summary icon={<BookCheck size={18} />} label="Pemetaan mata kuliah" value={summary.mapped_courses} />
        <Summary icon={<GitBranch size={18} />} label="Relasi prasyarat" value={summary.prerequisites} />
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-[.72fr_1.28fr]">
        <div className="space-y-6">
          {(abilities.create || editingCurriculum) && <section className="rounded-2xl bg-white p-6 ring-1 ring-slate-200/80">
            <div className="flex items-center gap-3"><div className="flex size-10 items-center justify-center rounded-xl bg-teal-50 text-teal-600">{editingCurriculum ? <Pencil size={18} /> : <Plus size={18} />}</div><div className="min-w-0 flex-1"><h2 className="font-semibold text-slate-950">{editingCurriculum ? 'Edit kurikulum' : 'Kurikulum baru'}</h2><p className="text-xs text-slate-400">Aktivasi bersifat eksklusif untuk setiap prodi.</p></div>{editingCurriculum && <button type="button" onClick={resetCurriculumForm} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100"><X size={16} /></button>}</div>
            <form onSubmit={submitCurriculum} className="mt-6 space-y-4">
              <Field label="Program studi"><select required value={curriculumForm.data.program_id} onChange={(event) => curriculumForm.setData('program_id', event.target.value)} className="input"><option value="">Pilih program studi</option>{programOptions.map((program) => <option key={program.id} value={program.id}>{program.code} · {program.name}</option>)}</select></Field>
              <Field label="Nama kurikulum"><input required value={curriculumForm.data.name} onChange={(event) => curriculumForm.setData('name', event.target.value)} className="input" placeholder="Kurikulum 2026" /></Field>
              <div className="grid gap-4 sm:grid-cols-2"><Field label="Kode"><input required value={curriculumForm.data.code} onChange={(event) => curriculumForm.setData('code', event.target.value.toUpperCase())} className="input" placeholder="KUR-2026" /></Field><Field label="Target SKS"><input required type="number" min="1" max="300" value={curriculumForm.data.target_credits} onChange={(event) => curriculumForm.setData('target_credits', Number(event.target.value))} className="input" /></Field></div>
              <Field label="Berlaku mulai"><select value={curriculumForm.data.effective_term_id} onChange={(event) => curriculumForm.setData('effective_term_id', event.target.value)} className="input"><option value="">Belum ditentukan</option>{termOptions.map((term) => <option key={term.id} value={term.id}>{term.code} · {term.name}</option>)}</select></Field>
              <Field label="Keterangan"><textarea value={curriculumForm.data.description} onChange={(event) => curriculumForm.setData('description', event.target.value)} className="input min-h-20" placeholder="Keterangan opsional" /></Field>
              <label className="flex items-center gap-2 text-xs font-semibold text-slate-600"><input type="checkbox" checked={curriculumForm.data.is_active} onChange={(event) => curriculumForm.setData('is_active', event.target.checked)} className="size-4 rounded border-slate-300 text-teal-600" /> Jadikan kurikulum aktif prodi</label>
              <Errors errors={curriculumForm.errors} />
              <button disabled={curriculumForm.processing} className="flex w-full items-center justify-center gap-2 rounded-xl bg-[#071827] py-3 font-semibold text-white disabled:opacity-60">{curriculumForm.processing ? 'Menyimpan...' : editingCurriculum ? 'Perbarui kurikulum' : 'Simpan kurikulum'} <Check size={16} /></button>
            </form>
          </section>}

          <section className="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/80">
            <form onSubmit={applyFilters} className="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2">
              <label className="relative sm:col-span-2"><Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input value={filterData.q} onChange={(event) => setFilterData({ ...filterData, q: event.target.value })} className="input pl-9" placeholder="Cari kurikulum" /></label>
              <select value={filterData.program_id} onChange={(event) => setFilterData({ ...filterData, program_id: event.target.value })} className="input"><option value="">Semua prodi</option>{programOptions.map((program) => <option key={program.id} value={program.id}>{program.code}</option>)}</select>
              <div className="flex gap-2"><select value={filterData.status} onChange={(event) => setFilterData({ ...filterData, status: event.target.value as Filters['status'] })} className="input"><option value="available">Tersedia</option><option value="archived">Arsip</option></select><button className="rounded-xl bg-teal-600 px-4 text-white"><Search size={16} /></button></div>
            </form>
            <div className="divide-y divide-slate-100">{curricula.data.map((item) => <button type="button" key={item.id} onClick={() => selectCurriculum(item.id)} className={`w-full p-4 text-left transition hover:bg-slate-50 ${selectedCurriculum?.id === item.id ? 'bg-teal-50/70' : ''}`}><div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-800">{item.name}</p><p className="mt-1 text-[11px] font-semibold uppercase tracking-wide text-teal-600">{item.code} · {item.program.code}</p></div>{item.is_active && <span className="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-semibold text-emerald-700">Aktif</span>}</div><p className="mt-2 text-xs text-slate-400">{item.curriculum_courses_count ?? 0} mata kuliah · {Number(item.assigned_credits ?? 0)} / {item.target_credits} SKS</p></button>)}{curricula.data.length === 0 && <p className="p-8 text-center text-xs text-slate-400">Belum ada kurikulum.</p>}</div>
            <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs text-slate-400"><span>Halaman {curricula.current_page} / {curricula.last_page}</span><div className="flex gap-1"><button onClick={() => paginate(curricula.current_page - 1)} disabled={curricula.current_page <= 1} className="rounded-lg p-1.5 hover:bg-slate-100 disabled:opacity-30"><ChevronLeft size={14} /></button><button onClick={() => paginate(curricula.current_page + 1)} disabled={curricula.current_page >= curricula.last_page} className="rounded-lg p-1.5 hover:bg-slate-100 disabled:opacity-30"><ChevronRight size={14} /></button></div></div>
          </section>
        </div>

        <div className="min-w-0 space-y-6">
          {!selectedCurriculum ? <section className="rounded-2xl bg-white p-12 text-center ring-1 ring-slate-200/80"><BookOpenCheck className="mx-auto text-slate-300" size={32} /><p className="mt-4 text-sm text-slate-500">Pilih atau buat kurikulum untuk mengelola struktur mata kuliah.</p></section> : <>
            <section className="rounded-2xl bg-white p-6 ring-1 ring-slate-200/80">
              <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"><div><div className="flex items-center gap-2"><h2 className="text-xl font-semibold text-slate-950">{selectedCurriculum.name}</h2>{selectedCurriculum.is_active && <span className="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-semibold text-emerald-700">Aktif</span>}{isArchived && <span className="rounded-full bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-700">Arsip</span>}</div><p className="mt-1 text-xs font-medium text-teal-600">{selectedCurriculum.code} · {selectedCurriculum.program.name}</p><p className="mt-2 text-xs text-slate-400">Berlaku mulai {selectedCurriculum.effective_term?.name ?? 'belum ditentukan'}</p></div><div className="flex gap-2">{isArchived ? abilities.update && <button onClick={() => router.patch(`/admin/curricula/${selectedCurriculum.id}/restore`)} className="rounded-xl bg-teal-50 p-2.5 text-teal-700" title="Pulihkan"><RotateCcw size={16} /></button> : <>{abilities.update && <button onClick={() => editCurriculum(selectedCurriculum)} className="rounded-xl bg-slate-100 p-2.5 text-slate-600" title="Edit"><Pencil size={16} /></button>}{abilities.delete && <button onClick={() => archive(selectedCurriculum)} className="rounded-xl bg-rose-50 p-2.5 text-rose-600" title="Arsipkan"><Trash2 size={16} /></button>}</>}</div></div>
              <div className="mt-5"><div className="flex justify-between text-xs"><span className="text-slate-500">Pemenuhan target SKS</span><span className="font-semibold text-slate-700">{assignedCredits} / {selectedCurriculum.target_credits}</span></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-teal-500" style={{ width: `${progress}%` }} /></div></div>
            </section>

            {!isArchived && abilities.update && <section className="rounded-2xl bg-white p-6 ring-1 ring-slate-200/80">
              <div className="flex items-center justify-between"><div><h3 className="font-semibold text-slate-900">{editingCourse ? 'Edit pemetaan' : 'Tambah mata kuliah'}</h3><p className="mt-1 text-xs text-slate-400">SKS dapat disesuaikan khusus pada kurikulum ini.</p></div>{editingCourse && <button onClick={resetCourseForm} className="rounded-lg p-2 text-slate-400"><X size={16} /></button>}</div>
              <form onSubmit={submitCourse} className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <select required value={courseForm.data.course_id} onChange={(event) => { const course = courseOptions.find((item) => item.id === Number(event.target.value)); courseForm.setData((data) => ({ ...data, course_id: event.target.value, credits: course?.credits ?? data.credits })); }} className="input md:col-span-2"><option value="">Pilih mata kuliah</option>{courseOptions.map((course) => <option key={course.id} value={course.id}>{course.code} · {course.name}</option>)}</select>
                <input required type="number" min="1" max="14" value={courseForm.data.semester} onChange={(event) => courseForm.setData('semester', Number(event.target.value))} className="input" placeholder="Semester" />
                <input required type="number" min="1" max="12" value={courseForm.data.credits} onChange={(event) => courseForm.setData('credits', Number(event.target.value))} className="input" placeholder="SKS" />
                <label className="flex items-center gap-2 text-xs font-semibold text-slate-600 md:col-span-2"><input type="checkbox" checked={courseForm.data.is_required} onChange={(event) => courseForm.setData('is_required', event.target.checked)} /> Mata kuliah wajib</label>
                <button disabled={courseForm.processing} className="rounded-xl bg-[#071827] px-4 py-2.5 text-sm font-semibold text-white md:col-span-2">{editingCourse ? 'Perbarui' : 'Tambahkan'}</button>
                <div className="md:col-span-2 xl:col-span-4"><Errors errors={courseForm.errors} /></div>
              </form>
            </section>}

            <section className="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/80">
              <div className="border-b border-slate-100 px-5 py-4"><h3 className="font-semibold text-slate-900">Struktur mata kuliah</h3><p className="mt-1 text-xs text-slate-400">Diurutkan berdasarkan semester penawaran.</p></div>
              <div className="divide-y divide-slate-100">{selectedCurriculum.curriculum_courses?.map((item) => <div key={item.id} className="group flex items-center gap-4 px-5 py-4"><span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-xs font-bold text-teal-700">S{item.semester}</span><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-800">{item.course.code} · {item.course.name}</p><p className="mt-1 text-xs text-slate-400">{item.credits} SKS · {item.is_required ? 'Wajib' : 'Pilihan'}</p></div>{!isArchived && abilities.update && <div className="flex gap-1"><button onClick={() => editCourse(item)} className="rounded-lg p-2 text-slate-300 hover:bg-teal-50 hover:text-teal-600"><Pencil size={14} /></button><button onClick={() => window.confirm('Hapus mata kuliah dari kurikulum?') && router.delete(`/admin/curricula/${selectedCurriculum.id}/courses/${item.id}`, { preserveScroll: true })} className="rounded-lg p-2 text-slate-300 hover:bg-rose-50 hover:text-rose-600"><Trash2 size={14} /></button></div>}</div>)}{!selectedCurriculum.curriculum_courses?.length && <p className="p-8 text-center text-xs text-slate-400">Belum ada mata kuliah pada kurikulum.</p>}</div>
            </section>

            {!isArchived && abilities.update && <section className="rounded-2xl bg-white p-6 ring-1 ring-slate-200/80">
              <div className="flex items-center gap-2"><GitBranch size={17} className="text-teal-600" /><h3 className="font-semibold text-slate-900">Prasyarat mata kuliah</h3></div>
              <form onSubmit={submitPrerequisite} className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_1fr_100px_auto]">
                <select required value={prerequisiteForm.data.course_id} onChange={(event) => prerequisiteForm.setData('course_id', event.target.value)} className="input"><option value="">Mata kuliah tujuan</option>{courseOptions.map((course) => <option key={course.id} value={course.id}>{course.code} · {course.name}</option>)}</select>
                <select required value={prerequisiteForm.data.prerequisite_course_id} onChange={(event) => prerequisiteForm.setData('prerequisite_course_id', event.target.value)} className="input"><option value="">Mata kuliah prasyarat</option>{courseOptions.map((course) => <option key={course.id} value={course.id}>{course.code} · {course.name}</option>)}</select>
                <select value={prerequisiteForm.data.minimum_grade} onChange={(event) => prerequisiteForm.setData('minimum_grade', event.target.value)} className="input">{['A', 'B+', 'B', 'C+', 'C', 'D'].map((grade) => <option key={grade}>{grade}</option>)}</select>
                <button disabled={prerequisiteForm.processing} className="rounded-xl bg-teal-600 px-4 text-sm font-semibold text-white">Tambah</button>
                <div className="md:col-span-2 xl:col-span-4"><Errors errors={prerequisiteForm.errors} /></div>
              </form>
              <div className="mt-5 divide-y divide-slate-100 border-t border-slate-100">{prerequisites.map((item) => <div key={item.id} className="flex items-center gap-3 py-3"><div className="min-w-0 flex-1"><p className="text-sm font-semibold text-slate-700">{item.course.code} <span className="font-normal text-slate-400">memerlukan</span> {item.prerequisite_course.code}</p><p className="mt-1 text-xs text-slate-400">Nilai minimum {item.minimum_grade}</p></div><button onClick={() => router.delete(`/admin/course-prerequisites/${item.id}`, { preserveScroll: true })} className="rounded-lg p-2 text-slate-300 hover:bg-rose-50 hover:text-rose-600"><Trash2 size={14} /></button></div>)}{prerequisites.length === 0 && <p className="py-5 text-center text-xs text-slate-400">Belum ada relasi prasyarat pada prodi ini.</p>}</div>
            </section>}
          </>}
        </div>
      </div>
    </DashboardLayout>
  );
}

function Summary({ icon, label, value }: { icon: ReactNode; label: string; value: number }) {
  return <div className="rounded-2xl bg-white p-4 ring-1 ring-slate-200/80"><div className="flex items-center justify-between"><span className="text-teal-600">{icon}</span><span className="text-2xl font-semibold tracking-tight text-slate-950">{value.toLocaleString('id-ID')}</span></div><p className="mt-3 text-xs font-medium text-slate-500">{label}</p></div>;
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return <label className="block"><span className="mb-2 block text-xs font-semibold text-slate-600">{label}</span>{children}</label>;
}

function Errors({ errors }: { errors: Partial<Record<string, string>> }) {
  return <>{Object.values(errors).map((error, index) => <p key={`${error}-${index}`} className="mt-1 text-xs text-rose-600">{error}</p>)}</>;
}
