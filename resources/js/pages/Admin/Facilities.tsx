import { Head, router, useForm } from '@inertiajs/react';
import {
  AlertTriangle,
  Archive,
  Building2,
  Check,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  DoorOpen,
  Download,
  FileSpreadsheet,
  Pencil,
  Plus,
  RotateCcw,
  Search,
  Trash2,
  Upload,
  UsersRound,
  X,
} from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { DashboardLayout } from '@/layouts/DashboardLayout';

type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
type CampusOption = { id: number; name: string };
type BuildingOption = { id: number; name: string; floor_count: number; campus: CampusOption };
type Building = {
  id: number;
  name: string;
  code: string;
  floor_count: number;
  description: string | null;
  is_active: boolean;
  deleted_at: string | null;
  campus: CampusOption;
  rooms_count: number;
};
type Room = {
  id: number;
  name: string;
  code: string;
  floor: number;
  type: string;
  capacity: number;
  facilities: string[] | null;
  is_active: boolean;
  deleted_at: string | null;
  building: { id: number; campus_id: number; name: string };
};
type Filters = { q: string; campus_id: string; type: string; status: 'active' | 'archived' };
type Props = {
  filters: Filters;
  buildings: Page<Building>;
  rooms: Page<Room>;
  campusOptions: CampusOption[];
  buildingOptions: BuildingOption[];
  summary: { buildings: number; rooms: number; capacity: number; archived: number };
  abilities: Record<Resource, { create: boolean; update: boolean; delete: boolean }>;
  importPreview: ImportPreview | null;
  transferAbilities: Record<Resource, { import: boolean; export: boolean }>;
};
type Resource = 'buildings' | 'rooms';
type ImportRow = { line: number; values: Record<string, string>; action: 'create' | 'update'; errors: Record<string, string[]> };
type ImportPreview = { token: string; resource: Resource; file_name: string; total_rows: number; valid_rows: number; error_rows: number; rows: ImportRow[] };
type FormData = {
  campus_id: string;
  building_id: string;
  name: string;
  code: string;
  floor_count: number;
  floor: number;
  description: string;
  type: string;
  capacity: number;
  facilities: string;
  is_active: boolean;
};

const initialData: FormData = {
  campus_id: '',
  building_id: '',
  name: '',
  code: '',
  floor_count: 1,
  floor: 1,
  description: '',
  type: 'Kelas',
  capacity: 30,
  facilities: '',
  is_active: true,
};
const roomTypes = ['Kelas', 'Laboratorium', 'Aula', 'Kantor', 'Perpustakaan', 'Lainnya'];

export default function Facilities({ filters, buildings, rooms, campusOptions, buildingOptions, summary, abilities, importPreview, transferAbilities }: Props) {
  const [resource, setResource] = useState<Resource>('buildings');
  const [editing, setEditing] = useState<{ resource: Resource; id: number } | null>(null);
  const [filterData, setFilterData] = useState(filters);
  const form = useForm<FormData>(initialData);
  const importForm = useForm<{ file: File | null }>({ file: null });
  const confirmForm = useForm({});
  const [selected, setSelected] = useState<Record<Resource, number[]>>({ buildings: [], rooms: [] });

  function resetForm() {
    setEditing(null);
    form.reset();
    form.clearErrors();
  }

  function selectResource(next: Resource) {
    setResource(next);
    resetForm();
  }

  function submit(event: FormEvent) {
    event.preventDefault();
    const options = { preserveScroll: true, onSuccess: resetForm };
    if (editing) {
      form.patch(`/admin/facilities/${editing.resource}/${editing.id}`, options);
      return;
    }
    form.post(`/admin/facilities/${resource}`, options);
  }

  function beginEdit(target: Resource, id: number, values: Partial<FormData>) {
    setResource(target);
    setEditing({ resource: target, id });
    form.setData({ ...initialData, ...values });
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function archive(target: Resource, id: number, label: string) {
    if (window.confirm(`Arsipkan ${label}?`)) {
      router.delete(`/admin/facilities/${target}/${id}`, { preserveScroll: true });
    }
  }

  function restore(target: Resource, id: number) {
    router.patch(`/admin/facilities/${target}/${id}/restore`, {}, { preserveScroll: true });
  }

  function applyFilters(event: FormEvent) {
    event.preventDefault();
    setSelected({ buildings: [], rooms: [] });
    router.get('/admin/facilities', filterData, { preserveState: true, replace: true });
  }

  function paginate(target: Resource, page: number) {
    setSelected((current) => ({ ...current, [target]: [] }));
    const pageKey = target === 'buildings' ? 'buildings_page' : 'rooms_page';
    router.get('/admin/facilities', { ...filterData, [pageKey]: page }, { preserveState: true, replace: true });
  }

  function previewImport(event: FormEvent) {
    event.preventDefault();
    importForm.post(`/admin/facilities/${resource}/import/preview`, { forceFormData: true, preserveScroll: true });
  }

  function confirmImport() { if (importPreview) confirmForm.post(`/admin/facilities/imports/${importPreview.token}/confirm`, { preserveScroll: true }); }
  function cancelImport() { if (importPreview) router.delete(`/admin/facilities/imports/${importPreview.token}`, { preserveScroll: true }); }
  function toggleSelected(target: Resource, id: number) { setSelected((current) => ({ ...current, [target]: current[target].includes(id) ? current[target].filter((item) => item !== id) : [...current[target], id] })); }
  function bulk(target: Resource) {
    const action = filterData.status === 'archived' ? 'restore' : 'archive';
    if (!selected[target].length || !window.confirm(`${action === 'restore' ? 'Pulihkan' : 'Arsipkan'} ${selected[target].length} data?`)) return;
    router.post(`/admin/facilities/${target}/bulk`, { action, ids: selected[target] }, { preserveScroll: true, onSuccess: () => setSelected((current) => ({ ...current, [target]: [] })) });
  }

  return (
    <DashboardLayout>
      <Head title="Gedung & Ruangan" />
      <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
        <div>
          <p className="text-sm font-medium text-teal-600">Master sarana</p>
          <h1 className="mt-2 text-3xl font-semibold tracking-[-.04em] text-slate-950">Gedung & Ruangan.</h1>
          <p className="mt-2 text-sm text-slate-500">Referensi lokasi terstruktur untuk kelas, jadwal, dan operasional kampus.</p>
        </div>
        <div className="flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm font-semibold text-slate-600 ring-1 ring-slate-200">
          <Archive size={16} className="text-teal-600" /> {summary.archived} data dalam arsip
        </div>
      </div>

      <div className="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Summary icon={<Building2 size={18} />} label="Gedung aktif" value={summary.buildings} />
        <Summary icon={<DoorOpen size={18} />} label="Ruangan aktif" value={summary.rooms} />
        <Summary icon={<UsersRound size={18} />} label="Kapasitas total" value={summary.capacity} />
        <Summary icon={<Archive size={18} />} label="Data diarsipkan" value={summary.archived} />
      </div>

      <section className="mt-6 rounded-2xl bg-white p-5 ring-1 ring-slate-200/80">
        <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"><div className="flex items-center gap-3"><div className="flex size-10 items-center justify-center rounded-xl bg-teal-50 text-teal-600"><FileSpreadsheet size={18} /></div><div><h2 className="text-sm font-semibold text-slate-900">Transfer CSV sarana</h2><p className="mt-1 text-xs text-slate-400">Referensi memakai kode kampus dan gedung · fasilitas dipisahkan tanda |</p></div></div><div className="flex gap-2">{(['buildings', 'rooms'] as Resource[]).map((item) => <button type="button" key={item} onClick={() => selectResource(item)} className={`rounded-full border px-3 py-1.5 text-xs font-semibold ${resource === item ? 'border-teal-300 bg-teal-50 text-teal-700' : 'border-slate-200 text-slate-400'}`}>{item === 'buildings' ? 'Gedung' : 'Ruangan'}</button>)}</div></div>
        <div className="mt-5 grid gap-3 lg:grid-cols-[1fr_auto_auto]"><form onSubmit={previewImport} className="flex min-w-0 gap-2"><input required type="file" accept=".csv,text/csv" onChange={(event) => importForm.setData('file', event.target.files?.[0] ?? null)} className="min-w-0 flex-1 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 ring-1 ring-slate-200 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white" /><button disabled={!transferAbilities[resource]?.import || importForm.processing} className="flex items-center gap-2 rounded-xl bg-teal-600 px-4 text-xs font-semibold text-white disabled:opacity-40"><Upload size={15} /> Preview</button></form>{transferAbilities[resource]?.export && <a href={`/admin/facilities/${resource}/template`} className="flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200"><FileSpreadsheet size={15} /> Template</a>}{transferAbilities[resource]?.export && <a href={`/admin/facilities/${resource}/export`} className="flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200"><Download size={15} /> Export</a>}</div>
        {importForm.errors.file && <p className="mt-3 text-xs text-rose-600">{importForm.errors.file}</p>}
      </section>

      {importPreview && <section className="mt-6 overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/80"><div className="flex flex-col gap-4 border-b border-slate-100 p-5 md:flex-row md:items-center md:justify-between"><div><div className="flex items-center gap-2">{importPreview.error_rows ? <AlertTriangle size={18} className="text-amber-500" /> : <CheckCircle2 size={18} className="text-emerald-500" />}<h2 className="font-semibold text-slate-900">Preview {importPreview.resource === 'buildings' ? 'gedung' : 'ruangan'}</h2></div><p className="mt-1 text-xs text-slate-400">{importPreview.file_name} · {importPreview.valid_rows} valid · {importPreview.error_rows} bermasalah</p></div><div className="flex gap-2"><button onClick={cancelImport} className="rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">Batalkan</button><button onClick={confirmImport} disabled={importPreview.error_rows > 0 || confirmForm.processing} className="rounded-xl bg-[#071827] px-4 py-2.5 text-xs font-semibold text-white disabled:opacity-40">Konfirmasi impor</button></div></div>
        {(confirmForm.errors as Record<string, string>).import && <p className="bg-rose-50 px-5 py-3 text-xs text-rose-600">{(confirmForm.errors as Record<string, string>).import}</p>}
        <div className="max-h-[380px] overflow-auto"><table className="w-full min-w-[720px] text-left text-xs"><thead className="sticky top-0 bg-slate-50 text-slate-500"><tr><th className="px-4 py-3">Baris</th><th className="px-4 py-3">Kode</th><th className="px-4 py-3">Nama</th><th className="px-4 py-3">Aksi</th><th className="px-4 py-3">Validasi</th></tr></thead><tbody className="divide-y divide-slate-100">{importPreview.rows.map((row) => { const errors = Object.values(row.errors).flat(); return <tr key={row.line} className={errors.length ? 'bg-rose-50/40' : ''}><td className="px-4 py-3">{row.line}</td><td className="px-4 py-3 font-semibold text-teal-700">{row.values.code || '—'}</td><td className="px-4 py-3">{row.values.name || '—'}</td><td className="px-4 py-3">{row.action === 'create' ? 'Buat' : 'Perbarui'}</td><td className="px-4 py-3">{errors.length ? <ul className="space-y-1 text-rose-600">{errors.map((error, index) => <li key={`${error}-${index}`}>{error}</li>)}</ul> : <span className="text-emerald-600">Siap diimpor</span>}</td></tr>; })}</tbody></table></div>
      </section>}

      <div className="mt-6 grid gap-6 xl:grid-cols-[.72fr_1.28fr]">
        <section className="h-fit rounded-2xl bg-white p-6 ring-1 ring-slate-200/80">
          <div className="flex items-center gap-3">
            <div className="flex size-10 items-center justify-center rounded-xl bg-teal-50 text-teal-600">{editing ? <Pencil size={18} /> : <Plus size={18} />}</div>
            <div className="min-w-0 flex-1">
              <h2 className="font-semibold text-slate-950">{editing ? 'Edit sarana' : 'Tambah sarana'}</h2>
              <p className="text-xs text-slate-400">Data mutasi dicatat pada audit log.</p>
            </div>
            {editing && <button type="button" onClick={resetForm} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100" title="Batal edit"><X size={16} /></button>}
          </div>

          {!editing && (
            <div className="mt-6 flex gap-2">
              {(['buildings', 'rooms'] as Resource[]).map((item) => (
                <button type="button" key={item} onClick={() => selectResource(item)} className={`rounded-full border px-3 py-1.5 text-xs font-semibold transition ${resource === item ? 'border-teal-300 bg-teal-50 text-teal-700' : 'border-slate-200 text-slate-400'}`}>
                  {item === 'buildings' ? 'Gedung' : 'Ruangan'}
                </button>
              ))}
            </div>
          )}

          {abilities[resource].create || editing ? <form onSubmit={submit} className="mt-6 space-y-4">
            {resource === 'buildings' ? (
              <>
                <Field label="Kampus"><select required value={form.data.campus_id} onChange={(event) => form.setData('campus_id', event.target.value)} className="input"><option value="">Pilih kampus</option>{campusOptions.map((campus) => <option key={campus.id} value={campus.id}>{campus.name}</option>)}</select></Field>
                <Field label="Nama gedung"><input required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className="input" placeholder="Gedung Rektorat" /></Field>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label="Kode"><input required value={form.data.code} onChange={(event) => form.setData('code', event.target.value.toUpperCase())} className="input" placeholder="GDR" /></Field>
                  <Field label="Jumlah lantai"><input required type="number" min="1" max="100" value={form.data.floor_count} onChange={(event) => form.setData('floor_count', Number(event.target.value))} className="input" /></Field>
                </div>
                <Field label="Keterangan"><textarea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} className="input min-h-20" placeholder="Keterangan opsional" /></Field>
              </>
            ) : (
              <>
                <Field label="Gedung"><select required value={form.data.building_id} onChange={(event) => form.setData('building_id', event.target.value)} className="input"><option value="">Pilih gedung</option>{buildingOptions.map((building) => <option key={building.id} value={building.id}>{building.name} · {building.campus.name}</option>)}</select></Field>
                <Field label="Nama ruangan"><input required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className="input" placeholder="Laboratorium Komputer 1" /></Field>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label="Kode"><input required value={form.data.code} onChange={(event) => form.setData('code', event.target.value.toUpperCase())} className="input" placeholder="LAB-01" /></Field>
                  <Field label="Lantai"><input required type="number" min="1" max="100" value={form.data.floor} onChange={(event) => form.setData('floor', Number(event.target.value))} className="input" /></Field>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label="Jenis"><select value={form.data.type} onChange={(event) => form.setData('type', event.target.value)} className="input">{roomTypes.map((type) => <option key={type}>{type}</option>)}</select></Field>
                  <Field label="Kapasitas"><input required type="number" min="1" max="10000" value={form.data.capacity} onChange={(event) => form.setData('capacity', Number(event.target.value))} className="input" /></Field>
                </div>
                <Field label="Fasilitas"><input value={form.data.facilities} onChange={(event) => form.setData('facilities', event.target.value)} className="input" placeholder="Proyektor, AC, Wi-Fi" /><span className="mt-1 block text-[11px] text-slate-400">Pisahkan setiap fasilitas dengan koma.</span></Field>
              </>
            )}
            <label className="flex items-center gap-2 text-xs font-semibold text-slate-600"><input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} className="size-4 rounded border-slate-300 text-teal-600" /> Sarana aktif</label>
            {Object.values(form.errors).map((error, index) => <p key={`${error}-${index}`} className="text-xs text-rose-600">{error}</p>)}
            <button disabled={form.processing} className="flex w-full items-center justify-center gap-2 rounded-xl bg-[#071827] py-3 font-semibold text-white transition hover:bg-[#0d3143] disabled:opacity-60">{form.processing ? 'Menyimpan...' : editing ? 'Perbarui data' : 'Simpan data'} <Check size={16} /></button>
          </form> : <p className="mt-6 rounded-xl bg-amber-50 p-4 text-xs text-amber-700">Anda memiliki akses lihat tanpa izin membuat data sarana.</p>}
        </section>

        <section className="min-w-0">
          <form onSubmit={applyFilters} className="mb-4 grid gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200/80 md:grid-cols-2 xl:grid-cols-4">
            <label className="relative md:col-span-2 xl:col-span-1"><Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input value={filterData.q} onChange={(event) => setFilterData({ ...filterData, q: event.target.value })} className="input pl-9" placeholder="Cari nama/kode" /></label>
            <select value={filterData.campus_id} onChange={(event) => setFilterData({ ...filterData, campus_id: event.target.value })} className="input"><option value="">Semua kampus</option>{campusOptions.map((campus) => <option key={campus.id} value={campus.id}>{campus.name}</option>)}</select>
            <select value={filterData.type} onChange={(event) => setFilterData({ ...filterData, type: event.target.value })} className="input"><option value="">Semua jenis</option>{roomTypes.map((type) => <option key={type}>{type}</option>)}</select>
            <div className="flex gap-2"><select value={filterData.status} onChange={(event) => setFilterData({ ...filterData, status: event.target.value as Filters['status'] })} className="input"><option value="active">Aktif</option><option value="archived">Arsip</option></select><button className="rounded-xl bg-teal-600 px-4 text-white hover:bg-teal-700" title="Terapkan filter"><Search size={16} /></button></div>
          </form>

          <div className="mb-4 grid gap-3 md:grid-cols-2">
            <BulkSelector title="Pilih gedung" items={buildings.data} selected={selected.buildings} enabled={filterData.status === 'archived' ? abilities.buildings.update : abilities.buildings.delete} action={filterData.status === 'archived' ? 'Pulihkan' : 'Arsipkan'} onToggle={(id) => toggleSelected('buildings', id)} onSubmit={() => bulk('buildings')} />
            <BulkSelector title="Pilih ruangan" items={rooms.data} selected={selected.rooms} enabled={filterData.status === 'archived' ? abilities.rooms.update : abilities.rooms.delete} action={filterData.status === 'archived' ? 'Pulihkan' : 'Arsipkan'} onToggle={(id) => toggleSelected('rooms', id)} onSubmit={() => bulk('rooms')} />
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <DataSection title="Gedung" icon={<Building2 size={16} />} page={buildings} abilities={abilities.buildings} onPage={(page) => paginate('buildings', page)} onEdit={(item) => beginEdit('buildings', item.id, { campus_id: String(item.campus.id), name: item.name, code: item.code, floor_count: item.floor_count, description: item.description ?? '', is_active: item.is_active })} onArchive={(item) => archive('buildings', item.id, item.name)} onRestore={(item) => restore('buildings', item.id)} details={(item) => <><Row label="Kampus" value={item.campus.name} /><Row label="Rincian" value={`${item.floor_count} lantai · ${item.rooms_count} ruangan`} /></>} />
            <DataSection title="Ruangan" icon={<DoorOpen size={16} />} page={rooms} abilities={abilities.rooms} onPage={(page) => paginate('rooms', page)} onEdit={(item) => beginEdit('rooms', item.id, { building_id: String(item.building.id), name: item.name, code: item.code, floor: item.floor, type: item.type, capacity: item.capacity, facilities: item.facilities?.join(', ') ?? '', is_active: item.is_active })} onArchive={(item) => archive('rooms', item.id, item.name)} onRestore={(item) => restore('rooms', item.id)} details={(item) => <><Row label="Lokasi" value={`${item.building.name} · lantai ${item.floor}`} /><Row label="Rincian" value={`${item.type} · ${item.capacity} orang`} />{item.facilities?.length ? <Row label="Fasilitas" value={item.facilities.join(', ')} /> : null}</>} />
          </div>
        </section>
      </div>
    </DashboardLayout>
  );
}

function Summary({ icon, label, value }: { icon: ReactNode; label: string; value: number }) {
  return <div className="rounded-2xl bg-white p-4 ring-1 ring-slate-200/80"><div className="flex items-center justify-between"><span className="text-teal-600">{icon}</span><span className="text-2xl font-semibold tracking-tight text-slate-950">{value.toLocaleString('id-ID')}</span></div><p className="mt-3 text-xs font-medium text-slate-500">{label}</p></div>;
}

function BulkSelector<T extends { id: number; name: string; code: string }>({ title, items, selected, enabled, action, onToggle, onSubmit }: { title: string; items: T[]; selected: number[]; enabled: boolean; action: string; onToggle: (id: number) => void; onSubmit: () => void }) {
  if (!enabled || items.length === 0) return null;
  return <div className="rounded-2xl bg-white p-4 ring-1 ring-slate-200/80"><div className="flex items-center justify-between gap-3"><p className="text-xs font-semibold text-slate-700">{title}</p><button disabled={selected.length === 0} onClick={onSubmit} className="rounded-lg bg-slate-900 px-3 py-1.5 text-[10px] font-semibold text-white disabled:opacity-30">{action} {selected.length || ''}</button></div><div className="mt-3 flex max-h-24 flex-wrap gap-2 overflow-auto">{items.map((item) => <label key={item.id} className={`flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-1.5 text-[10px] ring-1 ${selected.includes(item.id) ? 'bg-teal-50 text-teal-700 ring-teal-200' : 'text-slate-500 ring-slate-200'}`}><input type="checkbox" checked={selected.includes(item.id)} onChange={() => onToggle(item.id)} />{item.code} · {item.name}</label>)}</div></div>;
}

function DataSection<T extends { id: number; name: string; code: string; deleted_at: string | null }>({ title, icon, page, abilities, details, onPage, onEdit, onArchive, onRestore }: { title: string; icon: ReactNode; page: Page<T>; abilities: { update: boolean; delete: boolean }; details: (item: T) => ReactNode; onPage: (page: number) => void; onEdit: (item: T) => void; onArchive: (item: T) => void; onRestore: (item: T) => void }) {
  return <div className="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/80"><div className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div className="flex items-center gap-2 text-sm font-semibold text-slate-800"><span className="text-teal-600">{icon}</span>{title}</div><span className="text-xs text-slate-400">{page.total} data</span></div><div className="divide-y divide-slate-100">{page.data.map((item) => <div key={item.id} className="group px-5 py-4"><div className="flex items-start gap-3"><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-800">{item.name}</p><p className="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-teal-600">{item.code}</p><div className="mt-2 space-y-1 text-xs text-slate-400">{details(item)}</div></div><div className="flex gap-1 opacity-100 transition md:opacity-0 md:group-hover:opacity-100">{item.deleted_at ? abilities.update && <button onClick={() => onRestore(item)} title="Pulihkan" className="rounded-lg p-2 text-slate-300 hover:bg-teal-50 hover:text-teal-600"><RotateCcw size={14} /></button> : <>{abilities.update && <button onClick={() => onEdit(item)} title="Edit" className="rounded-lg p-2 text-slate-300 hover:bg-teal-50 hover:text-teal-600"><Pencil size={14} /></button>}{abilities.delete && <button onClick={() => onArchive(item)} title="Arsipkan" className="rounded-lg p-2 text-slate-300 hover:bg-rose-50 hover:text-rose-500"><Trash2 size={14} /></button>}</>}</div></div></div>)}{page.data.length === 0 && <p className="p-8 text-center text-xs text-slate-400">Tidak ada data ditemukan.</p>}</div><div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-400"><span>Halaman {page.current_page} / {page.last_page}</span><div className="flex gap-1"><button onClick={() => onPage(page.current_page - 1)} disabled={page.current_page <= 1} className="rounded-lg p-1.5 hover:bg-slate-100 disabled:opacity-30"><ChevronLeft size={14} /></button><button onClick={() => onPage(page.current_page + 1)} disabled={page.current_page >= page.last_page} className="rounded-lg p-1.5 hover:bg-slate-100 disabled:opacity-30"><ChevronRight size={14} /></button></div></div></div>;
}

function Row({ label, value }: { label: string; value: string }) {
  return <p><span className="text-slate-400">{label}:</span> {value}</p>;
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return <label className="block"><span className="mb-2 block text-xs font-semibold text-slate-600">{label}</span>{children}</label>;
}
