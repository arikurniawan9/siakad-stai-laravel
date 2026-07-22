import { Head, router, useForm } from '@inertiajs/react';
import { GripVertical, LayoutPanelTop, Pencil, Plus, Save, Trash2, X } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { DashboardLayout } from '@/layouts/DashboardLayout';

type Role = { name: string };
type Menu = {
  id: number;
  key: string;
  label: string;
  href: string | null;
  icon: string | null;
  permission: string | null;
  parent_id: number | null;
  sort_order: number;
  is_active: boolean;
  roles: Role[];
};
type Props = { menus: Menu[]; parents: Array<{ id: number; label: string }>; roles: string[] };

const initialData = {
  key: '',
  label: '',
  href: '',
  icon: 'LayoutDashboard',
  permission: '',
  parent_id: '',
  sort_order: 0,
  is_active: true,
  roles: ['Admin'] as string[],
};

export default function MenuBuilder({ menus, parents, roles }: Props) {
  const form = useForm(initialData);
  const [editingId, setEditingId] = useState<number | null>(null);

  function resetForm() {
    setEditingId(null);
    form.reset();
    form.clearErrors();
  }

  function beginEdit(menu: Menu) {
    setEditingId(menu.id);
    form.setData({
      key: menu.key,
      label: menu.label,
      href: menu.href ?? '',
      icon: menu.icon ?? 'LayoutDashboard',
      permission: menu.permission ?? '',
      parent_id: menu.parent_id?.toString() ?? '',
      sort_order: menu.sort_order,
      is_active: menu.is_active,
      roles: menu.roles.map((role) => role.name),
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function submit(event: FormEvent) {
    event.preventDefault();
    const options = { onSuccess: resetForm };

    if (editingId) {
      form.patch(`/admin/menu-builder/${editingId}`, options);
      return;
    }

    form.post('/admin/menu-builder', options);
  }

  function toggleRole(role: string) {
    form.setData('roles', form.data.roles.includes(role)
      ? form.data.roles.filter((item) => item !== role)
      : [...form.data.roles, role]);
  }

  function removeMenu(menu: Menu) {
    if (window.confirm(`Hapus menu "${menu.label}"?`)) {
      router.delete(`/admin/menu-builder/${menu.id}`);
    }
  }

  return <DashboardLayout>
    <Head title="Menu Builder" />

    <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
      <div>
        <p className="text-sm font-medium text-teal-600">System configuration</p>
        <h1 className="mt-2 text-3xl font-semibold tracking-[-.04em] text-slate-950">Menu Builder.</h1>
        <p className="mt-2 text-sm text-slate-500">Atur navigasi berdasarkan role dan permission tanpa mengubah source code.</p>
      </div>
      <div className="flex items-center gap-2 rounded-xl bg-teal-50 px-4 py-3 text-sm font-semibold text-teal-700">
        <LayoutPanelTop size={16} />{menus.length} menu terdaftar
      </div>
    </div>

    <div className="mt-8 grid gap-6 xl:grid-cols-[.78fr_1.22fr]">
      <section className="rounded-2xl bg-white p-6 ring-1 ring-slate-200/80">
        <div className="flex items-center gap-3">
          <div className="flex size-10 items-center justify-center rounded-xl bg-teal-50 text-teal-600">
            {editingId ? <Pencil size={18} /> : <Plus size={18} />}
          </div>
          <div className="min-w-0 flex-1">
            <h2 className="font-semibold text-slate-950">{editingId ? 'Edit menu' : 'Tambah menu'}</h2>
            <p className="text-xs text-slate-400">Permission tetap divalidasi server-side.</p>
          </div>
          {editingId && <button type="button" onClick={resetForm} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Batal edit"><X size={16} /></button>}
        </div>

        <form onSubmit={submit} className="mt-6 space-y-4">
          <Field label="Key"><input required value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} placeholder="akademik.khs" className="input" /></Field>
          <Field label="Label"><input required value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="KHS & Transkrip" className="input" /></Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="URL"><input value={form.data.href} onChange={(e) => form.setData('href', e.target.value)} placeholder="/dashboard/khs" className="input" /></Field>
            <Field label="Icon Lucide"><input value={form.data.icon} onChange={(e) => form.setData('icon', e.target.value)} placeholder="FileBarChart" className="input" /></Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Parent"><select value={form.data.parent_id} onChange={(e) => form.setData('parent_id', e.target.value)} className="input"><option value="">Menu utama</option>{parents.map((parent) => <option key={parent.id} value={parent.id}>{parent.label}</option>)}</select></Field>
            <Field label="Permission"><input value={form.data.permission} onChange={(e) => form.setData('permission', e.target.value)} placeholder="grades.view" className="input" /></Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Urutan"><input type="number" min="0" max="9999" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', Number(e.target.value))} className="input" /></Field>
            <label className="flex items-end gap-3 pb-3 text-sm font-medium text-slate-600"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500" /> Menu aktif</label>
          </div>
          <div>
            <p className="mb-2 text-xs font-semibold text-slate-600">Tampilkan untuk role</p>
            <div className="flex flex-wrap gap-2">{roles.map((role) => <button type="button" key={role} onClick={() => toggleRole(role)} className={`rounded-full border px-3 py-1.5 text-xs font-semibold transition ${form.data.roles.includes(role) ? 'border-teal-300 bg-teal-50 text-teal-700' : 'border-slate-200 text-slate-400 hover:border-slate-300'}`}>{role}</button>)}</div>
          </div>
          {form.errors.key && <p className="text-xs text-rose-600">{form.errors.key}</p>}
          <button disabled={form.processing} className="mt-2 flex w-full items-center justify-center gap-2 rounded-xl bg-[#071827] py-3 font-semibold text-white transition hover:bg-[#0d3143] disabled:opacity-60">
            {form.processing ? 'Menyimpan...' : editingId ? 'Perbarui menu' : 'Simpan menu'} {editingId ? <Save size={16} /> : <Plus size={16} />}
          </button>
        </form>
      </section>

      <section className="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/80">
        <div className="border-b border-slate-100 p-6"><h2 className="font-semibold text-slate-950">Struktur navigasi</h2><p className="mt-1 text-xs text-slate-400">Menu aktif akan difilter berdasarkan role aktif pengguna.</p></div>
        <div className="divide-y divide-slate-100">
          {menus.map((menu) => <div key={menu.id} className="flex items-center gap-3 px-6 py-4">
            <GripVertical size={16} className="text-slate-300" />
            <div className="flex size-9 items-center justify-center rounded-xl bg-slate-100 text-xs font-bold text-slate-500">{menu.sort_order + 1}</div>
            <div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-800">{menu.parent_id ? '↳ ' : ''}{menu.label}</p><p className="truncate text-xs text-slate-400">{menu.key} {menu.permission ? `· ${menu.permission}` : ''}</p></div>
            <div className="hidden gap-1 md:flex">{menu.roles.slice(0, 3).map((role) => <span key={role.name} className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-500">{role.name}</span>)}{menu.roles.length > 3 && <span className="rounded-full bg-teal-50 px-2 py-1 text-[10px] font-semibold text-teal-600">+{menu.roles.length - 3}</span>}</div>
            <span className={`size-2 rounded-full ${menu.is_active ? 'bg-emerald-400' : 'bg-slate-300'}`} />
            <button onClick={() => beginEdit(menu)} title="Edit menu" className="rounded-lg p-2 text-slate-300 transition hover:bg-teal-50 hover:text-teal-600"><Pencil size={15} /></button>
            <button onClick={() => removeMenu(menu)} title="Hapus menu" className="rounded-lg p-2 text-slate-300 transition hover:bg-rose-50 hover:text-rose-500"><Trash2 size={15} /></button>
          </div>)}
          {menus.length === 0 && <p className="p-10 text-center text-sm text-slate-400">Belum ada menu.</p>}
        </div>
      </section>
    </div>
  </DashboardLayout>;
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return <label className="block"><span className="mb-2 block text-xs font-semibold text-slate-600">{label}</span>{children}</label>;
}
