import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Archive, Building2, CalendarDays, Check, CheckCircle2, ChevronLeft, ChevronRight, Download, FileSpreadsheet, GraduationCap, Pencil, Plus, Search, Trash2, Upload, X } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { DashboardLayout } from '@/layouts/DashboardLayout';

type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
type Campus = { id: number; name: string; code: string; address: string | null; faculties_count: number; is_active: boolean };
type Faculty = { id: number; name: string; code: string; campus: { id: number; name: string } | null; programs_count: number };
type Program = { id: number; name: string; code: string; degree: string; faculty: { id: number; name: string } | null; courses_count: number; is_active: boolean };
type AcademicTerm = { id: number; name: string; code: string; semester: string; starts_on: string | null; ends_on: string | null; is_active: boolean };
type Course = { id: number; code: string; name: string; credits: number; type: string; program: { id: number; name: string } | null; is_active: boolean };
type Props = {
  filters: { q: string };
  campuses: Page<Campus>;
  faculties: Page<Faculty>;
  programs: Page<Program>;
  academicTerms: Page<AcademicTerm>;
  courses: Page<Course>;
  importPreview: ImportPreview | null;
  transferAbilities: Record<Resource, { import: boolean; export: boolean }>;
};
type Resource = 'campuses' | 'faculties' | 'programs' | 'academic-terms' | 'courses';
type FormData = { name: string; code: string; address: string; campus_id: string; faculty_id: string; program_id: string; degree: string; semester: string; starts_on: string; ends_on: string; credits: number; type: string; is_active: boolean };
type ImportRow = { line: number; values: Record<string, string>; action: 'create' | 'update'; errors: Record<string, string[]> };
type ImportPreview = { token: string; resource: Resource; file_name: string; total_rows: number; valid_rows: number; error_rows: number; rows: ImportRow[]; created_at: string };

const initialData: FormData = { name: '', code: '', address: '', campus_id: '', faculty_id: '', program_id: '', degree: 'S1', semester: 'Ganjil', starts_on: '', ends_on: '', credits: 3, type: 'Wajib', is_active: true };
const resourceLabels: Record<Resource, string> = { campuses: 'Kampus', faculties: 'Fakultas', programs: 'Program studi', 'academic-terms': 'Periode akademik', courses: 'Mata kuliah' };

export default function MasterData({ filters, campuses, faculties, programs, academicTerms, courses, importPreview, transferAbilities }: Props) {
  const [resource, setResource] = useState<Resource>('campuses');
  const [editing, setEditing] = useState<{ resource: Resource; id: number } | null>(null);
  const [query, setQuery] = useState(filters.q);
  const form = useForm<FormData>(initialData);
  const importForm = useForm<{ file: File | null }>({ file: null });
  const confirmForm = useForm({});

  function resetForm() { setEditing(null); form.reset(); form.clearErrors(); }
  function selectResource(value: Resource) { setResource(value); setEditing(null); form.reset(); form.clearErrors(); }
  function submit(event: FormEvent) {
    event.preventDefault();
    const options = { onSuccess: resetForm };
    if (editing) { form.patch(`/admin/master-data/${editing.resource}/${editing.id}`, options); return; }
    form.post(`/admin/master-data/${resource}`, options);
  }
  function beginEdit(target: Resource, id: number, values: Partial<FormData>) {
    setResource(target); setEditing({ resource: target, id }); form.setData({ ...initialData, ...values }); window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function remove(target: Resource, id: number, label: string) {
    if (window.confirm(`Arsipkan ${label}?`)) router.delete(`/admin/master-data/${target}/${id}`);
  }
  function search(event: FormEvent) { event.preventDefault(); router.get('/admin/master-data', { q: query }, { preserveState: true, replace: true }); }
  function paginate(page: number) { router.get('/admin/master-data', { q: query, page }, { preserveState: true, replace: true }); }
  function previewImport(event: FormEvent) {
    event.preventDefault();
    importForm.post(`/admin/master-data/${resource}/import/preview`, { forceFormData: true, preserveScroll: true });
  }
  function confirmImport() { if (importPreview) confirmForm.post(`/admin/master-data/imports/${importPreview.token}/confirm`, { preserveScroll: true }); }
  function cancelImport() { if (importPreview) router.delete(`/admin/master-data/imports/${importPreview.token}`, { preserveScroll: true }); }

  return <DashboardLayout>
    <Head title="Master Data" />
    <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
      <div><p className="text-sm font-medium text-teal-600">Academic foundation</p><h1 className="mt-2 text-3xl font-semibold tracking-[-.04em] text-slate-950">Master Data.</h1><p className="mt-2 text-sm text-slate-500">Kelola struktur kampus dan referensi akademik dengan relasi yang tervalidasi.</p></div>
      <div className="flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm font-semibold text-slate-600 ring-1 ring-slate-200"><Archive size={16} className="text-teal-600" /> Data terhubung ke database</div>
    </div>

    <div className="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
      <Summary icon={<Building2 size={18} />} label="Kampus" value={campuses.total} />
      <Summary icon={<Building2 size={18} />} label="Fakultas" value={faculties.total} />
      <Summary icon={<GraduationCap size={18} />} label="Program studi" value={programs.total} />
      <Summary icon={<CalendarDays size={18} />} label="Periode" value={academicTerms.total} />
      <Summary icon={<GraduationCap size={18} />} label="Mata kuliah" value={courses.total} />
    </div>

    <section className="mt-6 rounded-2xl bg-white p-5 ring-1 ring-slate-200/80">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"><div className="flex items-center gap-3"><div className="flex size-10 items-center justify-center rounded-xl bg-teal-50 text-teal-600"><FileSpreadsheet size={18} /></div><div><h2 className="text-sm font-semibold text-slate-900">Transfer CSV master data</h2><p className="mt-1 text-xs text-slate-400">Preview wajib sebelum impor · UTF-8 · maksimal 2 MB atau 500 baris</p></div></div><div className="flex flex-wrap gap-2">{(Object.keys(resourceLabels) as Resource[]).map((item) => <button type="button" key={item} onClick={() => selectResource(item)} className={`rounded-full border px-3 py-1.5 text-xs font-semibold transition ${resource === item ? 'border-teal-300 bg-teal-50 text-teal-700' : 'border-slate-200 text-slate-400 hover:border-slate-300'}`}>{resourceLabels[item]}</button>)}</div></div>
      <div className="mt-5 grid gap-3 lg:grid-cols-[1fr_auto_auto]"><form onSubmit={previewImport} className="flex min-w-0 gap-2"><input required type="file" accept=".csv,text/csv" onChange={(event) => importForm.setData('file', event.target.files?.[0] ?? null)} className="min-w-0 flex-1 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 ring-1 ring-slate-200 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white" /><button disabled={!transferAbilities[resource]?.import || importForm.processing} className="flex items-center gap-2 rounded-xl bg-teal-600 px-4 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40"><Upload size={15} /> Preview</button></form>{transferAbilities[resource]?.export && <a href={`/admin/master-data/${resource}/template`} className="flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50"><FileSpreadsheet size={15} /> Template</a>}{transferAbilities[resource]?.export && <a href={`/admin/master-data/${resource}/export`} className="flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50"><Download size={15} /> Export</a>}</div>
      {importForm.errors.file && <p className="mt-3 text-xs text-rose-600">{importForm.errors.file}</p>}
      {!transferAbilities[resource]?.import && <p className="mt-3 text-xs text-amber-600">Impor membutuhkan izin membuat dan memperbarui {resourceLabels[resource].toLowerCase()}.</p>}
    </section>

    {importPreview && <section className="mt-6 overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/80"><div className="flex flex-col gap-4 border-b border-slate-100 p-5 md:flex-row md:items-center md:justify-between"><div><div className="flex items-center gap-2">{importPreview.error_rows === 0 ? <CheckCircle2 size={18} className="text-emerald-500" /> : <AlertTriangle size={18} className="text-amber-500" />}<h2 className="font-semibold text-slate-900">Preview {resourceLabels[importPreview.resource]}</h2></div><p className="mt-1 text-xs text-slate-400">{importPreview.file_name} · {importPreview.total_rows} baris · {importPreview.valid_rows} valid · {importPreview.error_rows} bermasalah</p></div><div className="flex gap-2"><button onClick={cancelImport} className="rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">Batalkan</button><button onClick={confirmImport} disabled={importPreview.error_rows > 0 || confirmForm.processing} className="rounded-xl bg-[#071827] px-4 py-2.5 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40">{confirmForm.processing ? 'Mengimpor...' : 'Konfirmasi impor'}</button></div></div>
      {(confirmForm.errors as Record<string, string>).import && <p className="border-b border-rose-100 bg-rose-50 px-5 py-3 text-xs text-rose-600">{(confirmForm.errors as Record<string, string>).import}</p>}
      <div className="max-h-[430px] overflow-auto"><table className="w-full min-w-[720px] text-left text-xs"><thead className="sticky top-0 bg-slate-50 text-slate-500"><tr><th className="px-4 py-3">Baris</th><th className="px-4 py-3">Kode</th><th className="px-4 py-3">Nama</th><th className="px-4 py-3">Aksi</th><th className="px-4 py-3">Validasi</th></tr></thead><tbody className="divide-y divide-slate-100">{importPreview.rows.map((row) => { const errors = Object.values(row.errors).flat(); return <tr key={row.line} className={errors.length ? 'bg-rose-50/40' : ''}><td className="px-4 py-3 font-semibold text-slate-500">{row.line}</td><td className="px-4 py-3 font-semibold text-teal-700">{row.values.code || '—'}</td><td className="px-4 py-3 text-slate-700">{row.values.name || '—'}</td><td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-[10px] font-semibold ${row.action === 'create' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700'}`}>{row.action === 'create' ? 'Buat' : 'Perbarui'}</span></td><td className="px-4 py-3">{errors.length ? <ul className="space-y-1 text-rose-600">{errors.map((error, index) => <li key={`${error}-${index}`}>{error}</li>)}</ul> : <span className="text-emerald-600">Siap diimpor</span>}</td></tr>; })}</tbody></table></div>
    </section>}

    <div className="mt-6 grid gap-6 xl:grid-cols-[.72fr_1.28fr]">
      <section className="rounded-2xl bg-white p-6 ring-1 ring-slate-200/80">
        <div className="flex items-center gap-3"><div className="flex size-10 items-center justify-center rounded-xl bg-teal-50 text-teal-600">{editing ? <Pencil size={18} /> : <Plus size={18} />}</div><div className="min-w-0 flex-1"><h2 className="font-semibold text-slate-950">{editing ? 'Edit data' : 'Tambah data'}</h2><p className="text-xs text-slate-400">{editing ? `Memperbarui ${resourceLabels[resource].toLowerCase()}` : 'Pilih jenis referensi yang ingin dibuat.'}</p></div>{editing && <button type="button" onClick={resetForm} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100" title="Batal edit"><X size={16} /></button>}</div>
        {!editing && <div className="mt-6 flex flex-wrap gap-2">{(Object.keys(resourceLabels) as Resource[]).map((item) => <button type="button" key={item} onClick={() => selectResource(item)} className={`rounded-full border px-3 py-1.5 text-xs font-semibold transition ${resource === item ? 'border-teal-300 bg-teal-50 text-teal-700' : 'border-slate-200 text-slate-400 hover:border-slate-300'}`}>{resourceLabels[item]}</button>)}</div>}
        <form onSubmit={submit} className="mt-6 space-y-4">
          {resource === 'campuses' && <><Field label="Nama kampus"><input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="input" placeholder="Kampus Utama" /></Field><Field label="Kode"><input required value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className="input" placeholder="STAI-01" /></Field><Field label="Alamat"><textarea value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} className="input min-h-20" placeholder="Alamat kampus" /></Field></>}
          {resource === 'faculties' && <><Field label="Kampus"><select value={form.data.campus_id} onChange={(e) => form.setData('campus_id', e.target.value)} className="input"><option value="">Pilih kampus</option>{campuses.data.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></Field><Field label="Nama fakultas"><input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="input" placeholder="Fakultas Teknologi Informasi" /></Field><Field label="Kode"><input required value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className="input" placeholder="FTI" /></Field></>}
          {resource === 'programs' && <><Field label="Fakultas"><select value={form.data.faculty_id} onChange={(e) => form.setData('faculty_id', e.target.value)} className="input"><option value="">Pilih fakultas</option>{faculties.data.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></Field><Field label="Nama program studi"><input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="input" placeholder="Teknik Informatika" /></Field><div className="grid gap-4 sm:grid-cols-2"><Field label="Kode"><input required value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className="input" placeholder="TI-S1" /></Field><Field label="Jenjang"><input required value={form.data.degree} onChange={(e) => form.setData('degree', e.target.value)} className="input" placeholder="S1" /></Field></div></>}
          {resource === 'academic-terms' && <><Field label="Nama periode"><input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="input" placeholder="Tahun Akademik 2026/2027" /></Field><div className="grid gap-4 sm:grid-cols-2"><Field label="Kode"><input required value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className="input" placeholder="2026-GANJIL" /></Field><Field label="Semester"><select value={form.data.semester} onChange={(e) => form.setData('semester', e.target.value)} className="input"><option>Ganjil</option><option>Genap</option><option>Pendek</option></select></Field></div><div className="grid gap-4 sm:grid-cols-2"><Field label="Mulai"><input type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} className="input" /></Field><Field label="Berakhir"><input type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} className="input" /></Field></div></>}
          {resource === 'courses' && <><Field label="Program studi"><select value={form.data.program_id} onChange={(e) => form.setData('program_id', e.target.value)} className="input"><option value="">Pilih program studi</option>{programs.data.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></Field><div className="grid gap-4 sm:grid-cols-2"><Field label="Kode mata kuliah"><input required value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className="input" placeholder="IF601" /></Field><Field label="SKS"><input type="number" min="1" max="12" required value={form.data.credits} onChange={(e) => form.setData('credits', Number(e.target.value))} className="input" /></Field></div><Field label="Nama mata kuliah"><input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="input" placeholder="Rekayasa Perangkat Lunak" /></Field><Field label="Jenis"><select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} className="input"><option>Wajib</option><option>Pilihan</option></select></Field></>}
          {form.errors.code && <p className="text-xs text-rose-600">{form.errors.code}</p>}
          <button disabled={form.processing} className="flex w-full items-center justify-center gap-2 rounded-xl bg-[#071827] py-3 font-semibold text-white transition hover:bg-[#0d3143] disabled:opacity-60">{form.processing ? 'Menyimpan...' : editing ? 'Perbarui data' : 'Simpan data'} <Check size={16} /></button>
        </form>
      </section>

      <section className="min-w-0">
        <form onSubmit={search} className="relative mb-4"><Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input value={query} onChange={(e) => setQuery(e.target.value)} className="w-full rounded-xl border-0 bg-white py-3 pl-9 pr-4 text-sm ring-1 ring-slate-200 outline-none focus:ring-2 focus:ring-teal-300" placeholder="Cari nama atau kode..." /></form>
        <div className="grid gap-4 md:grid-cols-2">
          <DataSection title="Kampus" icon={<Building2 size={16} />} page={campuses} onPage={paginate} onEdit={(item) => beginEdit('campuses', item.id, { name: item.name, code: item.code, address: item.address ?? '' })} onDelete={(item) => remove('campuses', item.id, item.name)} details={(item) => <Row label="Fakultas" value={String(item.faculties_count)} />} />
          <DataSection title="Fakultas" icon={<Building2 size={16} />} page={faculties} onPage={paginate} onEdit={(item) => beginEdit('faculties', item.id, { name: item.name, code: item.code, campus_id: item.campus?.id.toString() ?? '' })} onDelete={(item) => remove('faculties', item.id, item.name)} details={(item) => <><Row label="Kampus" value={item.campus?.name ?? '—'} /><Row label="Program" value={String(item.programs_count)} /></>} />
          <DataSection title="Program studi" icon={<GraduationCap size={16} />} page={programs} onPage={paginate} onEdit={(item) => beginEdit('programs', item.id, { name: item.name, code: item.code, degree: item.degree, faculty_id: item.faculty?.id.toString() ?? '' })} onDelete={(item) => remove('programs', item.id, item.name)} details={(item) => <><Row label="Fakultas" value={item.faculty?.name ?? '—'} /><Row label="Mata kuliah" value={String(item.courses_count)} /></>} />
          <DataSection title="Periode akademik" icon={<CalendarDays size={16} />} page={academicTerms} onPage={paginate} onEdit={(item) => beginEdit('academic-terms', item.id, { name: item.name, code: item.code, semester: item.semester, starts_on: item.starts_on?.slice(0, 10) ?? '', ends_on: item.ends_on?.slice(0, 10) ?? '', is_active: item.is_active })} onDelete={(item) => remove('academic-terms', item.id, item.name)} details={(item) => <><Row label="Semester" value={item.semester} /><Row label="Status" value={item.is_active ? 'Aktif' : 'Arsip'} /></>} />
          <DataSection title="Mata kuliah" icon={<GraduationCap size={16} />} page={courses} onPage={paginate} onEdit={(item) => beginEdit('courses', item.id, { name: item.name, code: item.code, credits: item.credits, type: item.type, program_id: item.program?.id.toString() ?? '' })} onDelete={(item) => remove('courses', item.id, item.name)} details={(item) => <><Row label="Program" value={item.program?.name ?? '—'} /><Row label="Beban" value={`${item.credits} SKS · ${item.type}`} /></>} />
        </div>
      </section>
    </div>
  </DashboardLayout>;
}

function Summary({ icon, label, value }: { icon: ReactNode; label: string; value: number }) { return <div className="rounded-2xl bg-white p-4 ring-1 ring-slate-200/80"><div className="flex items-center justify-between"><span className="text-teal-600">{icon}</span><span className="text-2xl font-semibold tracking-tight text-slate-950">{value}</span></div><p className="mt-3 text-xs font-medium text-slate-500">{label}</p></div>; }

function DataSection<T extends { id: number; name: string; code: string }>({ title, icon, page, details, onPage, onEdit, onDelete }: { title: string; icon: ReactNode; page: Page<T>; details: (item: T) => ReactNode; onPage: (page: number) => void; onEdit: (item: T) => void; onDelete: (item: T) => void }) {
  return <div className="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/80"><div className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div className="flex items-center gap-2 text-sm font-semibold text-slate-800"><span className="text-teal-600">{icon}</span>{title}</div><span className="text-xs text-slate-400">{page.total} data</span></div><div className="divide-y divide-slate-100">{page.data.map((item) => <div key={item.id} className="group px-5 py-4"><div className="flex items-start gap-3"><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-800">{item.name}</p><p className="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-teal-600">{item.code}</p><div className="mt-2 space-y-1 text-xs text-slate-400">{details(item)}</div></div><div className="flex gap-1 opacity-0 transition group-hover:opacity-100"><button onClick={() => onEdit(item)} title="Edit" className="rounded-lg p-2 text-slate-300 hover:bg-teal-50 hover:text-teal-600"><Pencil size={14} /></button><button onClick={() => onDelete(item)} title="Arsipkan" className="rounded-lg p-2 text-slate-300 hover:bg-rose-50 hover:text-rose-500"><Trash2 size={14} /></button></div></div></div>)}{page.data.length === 0 && <p className="p-8 text-center text-xs text-slate-400">Tidak ada data ditemukan.</p>}</div><div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-400"><span>Halaman {page.current_page} / {page.last_page}</span><div className="flex gap-1"><button onClick={() => onPage(page.current_page - 1)} disabled={page.current_page <= 1} className="rounded-lg p-1.5 hover:bg-slate-100 disabled:opacity-30"><ChevronLeft size={14} /></button><button onClick={() => onPage(page.current_page + 1)} disabled={page.current_page >= page.last_page} className="rounded-lg p-1.5 hover:bg-slate-100 disabled:opacity-30"><ChevronRight size={14} /></button></div></div></div>;
}

function Row({ label, value }: { label: string; value: string }) { return <p><span className="text-slate-400">{label}:</span> {value}</p>; }
function Field({ label, children }: { label: string; children: ReactNode }) { return <label className="block"><span className="mb-2 block text-xs font-semibold text-slate-600">{label}</span>{children}</label>; }
