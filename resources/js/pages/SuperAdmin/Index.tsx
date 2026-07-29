import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, Copy, Database, Download, FileArchive, HardDrive, KeyRound, Landmark, LogOut, RefreshCw, Save, Server, Settings2, ShieldCheck, Trash2, Upload } from 'lucide-react';
import { useState, type FormEvent } from 'react';

type Backup = { filename: string; size: number; modified_at: string };
type DatabaseStatus = { driver: string; database: string; exists: boolean; size: number | null };
type BsiSettings = {
  driver: string;
  enabled: boolean;
  environment: 'sandbox' | 'production';
  base_url: string | null;
  callback_secret_configured: boolean;
  timeout: number;
  signature_tolerance_seconds: number;
  strategy: 'student' | 'invoice';
};
type Props = {
  database: DatabaseStatus;
  backups: Backup[];
  bsi: BsiSettings;
  callbackUrl: string;
  realAdapterAvailable: boolean;
  limits: { restoreSizeKb: number };
};
type Tab = 'overview' | 'bsi' | 'database';

export default function SuperAdminPortal({ database, backups, bsi, callbackUrl, realAdapterAvailable, limits }: Props) {
  const [tab, setTab] = useState<Tab>('overview');
  const page = usePage<{ flash?: { success?: string; error?: string }; auth: { user: { name: string; email: string } } }>();
  const flash = page.props.flash;

  return (
    <>
      <Head title="Super Admin" />
      <div className="min-h-screen bg-[#f3f6f8] text-slate-900">
        <header className="border-b border-slate-200 bg-[#07111f] text-white">
          <div className="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 lg:px-8">
            <div className="flex items-center gap-3">
              <span className="flex size-9 items-center justify-center rounded-md bg-cyan-300 text-slate-950"><ShieldCheck size={19} /></span>
              <div><p className="text-sm font-semibold">SIAKAD.OS</p><p className="text-[10px] text-slate-400">Super Admin Control</p></div>
            </div>
            <div className="flex items-center gap-2">
              <a href="/" title="Portal utama" className="p-2 text-slate-400 hover:text-white"><ArrowLeft size={18} /></a>
              <button onClick={() => router.post('/superadmin/logout')} title="Keluar" className="p-2 text-slate-400 hover:text-white"><LogOut size={18} /></button>
            </div>
          </div>
        </header>

        <div className="mx-auto grid max-w-7xl lg:grid-cols-[230px_1fr]">
          <aside className="border-b border-slate-200 bg-white px-4 py-5 lg:min-h-[calc(100vh-73px)] lg:border-b-0 lg:border-r">
            <div className="mb-6 px-2">
              <p className="truncate text-xs font-semibold text-slate-800">{page.props.auth.user.name}</p>
              <p className="mt-1 truncate text-[10px] text-slate-400">{page.props.auth.user.email}</p>
            </div>
            <nav className="flex gap-1 overflow-x-auto lg:block lg:space-y-1">
              <Nav active={tab === 'overview'} icon={<Server size={16} />} label="Ringkasan" onClick={() => setTab('overview')} />
              <Nav active={tab === 'bsi'} icon={<Landmark size={16} />} label="Koneksi VA BSI" onClick={() => setTab('bsi')} />
              <Nav active={tab === 'database'} icon={<Database size={16} />} label="Database" onClick={() => setTab('database')} />
            </nav>
          </aside>

          <main className="min-w-0 px-5 py-7 lg:px-9 lg:py-9">
            {flash?.success && <Notice tone="success" text={flash.success} />}
            {flash?.error && <Notice tone="error" text={flash.error} />}
            {tab === 'overview' && <Overview database={database} backups={backups} bsi={bsi} onNavigate={setTab} />}
            {tab === 'bsi' && <BsiPanel settings={bsi} callbackUrl={callbackUrl} realAdapterAvailable={realAdapterAvailable} />}
            {tab === 'database' && <DatabasePanel database={database} backups={backups} maxRestoreSizeKb={limits.restoreSizeKb} />}
          </main>
        </div>
      </div>
    </>
  );
}

function Overview({ database, backups, bsi, onNavigate }: { database: DatabaseStatus; backups: Backup[]; bsi: BsiSettings; onNavigate: (tab: Tab) => void }) {
  return (
    <>
      <Title eyebrow="System control" title="Ringkasan operasional" text="Status layanan inti dan pintasan pemeliharaan sistem." />
      <div className="mt-7 grid gap-4 sm:grid-cols-3">
        <Stat icon={<Database size={18} />} label="Database" value={database.exists ? 'Terhubung' : 'Tidak tersedia'} detail={`${database.driver.toUpperCase()} / ${database.database}`} tone={database.exists ? 'cyan' : 'red'} />
        <Stat icon={<FileArchive size={18} />} label="Backup tersimpan" value={String(backups.length)} detail={backups[0] ? formatDate(backups[0].modified_at) : 'Belum ada backup'} tone="green" />
        <Stat icon={<Landmark size={18} />} label="VA BSI" value={bsi.enabled ? 'Simulasi aktif' : 'Nonaktif'} detail={`${bsi.environment} / ${bsi.driver}`} tone="amber" />
      </div>
      <section className="mt-8 border-t border-slate-200 pt-7">
        <h2 className="text-sm font-semibold">Tindakan utama</h2>
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          <button onClick={() => onNavigate('bsi')} className="flex items-center justify-between rounded-md border border-slate-200 bg-white p-5 text-left hover:border-cyan-300">
            <span><span className="block text-sm font-semibold">Konfigurasi Virtual Account</span><span className="mt-1 block text-xs text-slate-400">Endpoint, callback, timeout, dan strategi VA.</span></span><Settings2 size={19} className="text-cyan-600" />
          </button>
          <button onClick={() => onNavigate('database')} className="flex items-center justify-between rounded-md border border-slate-200 bg-white p-5 text-left hover:border-cyan-300">
            <span><span className="block text-sm font-semibold">Pemeliharaan database</span><span className="mt-1 block text-xs text-slate-400">Backup, restore, dan penghapusan terkontrol.</span></span><HardDrive size={19} className="text-cyan-600" />
          </button>
        </div>
      </section>
    </>
  );
}

function BsiPanel({ settings, callbackUrl, realAdapterAvailable }: { settings: BsiSettings; callbackUrl: string; realAdapterAvailable: boolean }) {
  const form = useForm({
    enabled: settings.enabled,
    environment: settings.environment,
    base_url: settings.base_url ?? '',
    callback_secret: '',
    timeout: settings.timeout,
    signature_tolerance_seconds: settings.signature_tolerance_seconds,
    strategy: settings.strategy,
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.put('/superadmin/settings/bsi', { preserveScroll: true });
  }

  return (
    <>
      <Title eyebrow="Bank integration" title="Koneksi VA BSI" text="Konfigurasi disimpan terenkripsi di storage privat aplikasi." />
      {!realAdapterAvailable && (
        <div className="mt-6 flex gap-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          <AlertTriangle size={18} className="mt-0.5 shrink-0" />
          <p>Driver yang tersedia saat ini hanya <strong>bsi-fake</strong> untuk simulasi lokal. Konfigurasi production dapat disiapkan, tetapi tidak dapat diaktifkan sebelum adapter kontrak resmi BSI ditambahkan.</p>
        </div>
      )}
      <form onSubmit={submit} className="mt-7 max-w-3xl">
        <div className="grid gap-5 border-y border-slate-200 py-6 sm:grid-cols-2">
          <Field label="Driver"><input className="control bg-slate-100" value="bsi-fake" disabled /></Field>
          <Field label="Environment" error={form.errors.environment}>
            <select className="control" value={form.data.environment} onChange={(event) => form.setData('environment', event.target.value as 'sandbox' | 'production')}>
              <option value="sandbox">Sandbox / lokal</option><option value="production">Production</option>
            </select>
          </Field>
          <div className="sm:col-span-2"><Field label="Base URL API" error={form.errors.base_url}><input className="control" placeholder="https://api-bank.example" value={form.data.base_url} onChange={(event) => form.setData('base_url', event.target.value)} /></Field></div>
          <div className="sm:col-span-2"><Field label={`Callback secret${settings.callback_secret_configured ? ' (sudah tersimpan)' : ''}`} error={form.errors.callback_secret}><input type="password" className="control" placeholder={settings.callback_secret_configured ? 'Kosongkan untuk mempertahankan secret lama' : 'Minimal 16 karakter'} value={form.data.callback_secret} onChange={(event) => form.setData('callback_secret', event.target.value)} /></Field></div>
          <Field label="Timeout request (detik)" error={form.errors.timeout}><input type="number" min={1} max={120} className="control" value={form.data.timeout} onChange={(event) => form.setData('timeout', Number(event.target.value))} /></Field>
          <Field label="Toleransi signature (detik)" error={form.errors.signature_tolerance_seconds}><input type="number" min={30} max={3600} className="control" value={form.data.signature_tolerance_seconds} onChange={(event) => form.setData('signature_tolerance_seconds', Number(event.target.value))} /></Field>
          <Field label="Strategi VA" error={form.errors.strategy}><select className="control" value={form.data.strategy} onChange={(event) => form.setData('strategy', event.target.value as 'student' | 'invoice')}><option value="student">Satu VA per mahasiswa</option><option value="invoice">Satu VA per invoice</option></select></Field>
          <label className="flex items-center gap-3 self-end rounded-md border border-slate-200 bg-white px-4 py-3 text-sm">
            <input type="checkbox" className="size-4 accent-cyan-600" checked={form.data.enabled} onChange={(event) => form.setData('enabled', event.target.checked)} />
            Aktifkan integrasi
          </label>
        </div>
        {form.errors.enabled && <p className="mt-3 text-xs text-rose-600">{form.errors.enabled}</p>}
        <div className="mt-5 flex flex-wrap items-center justify-between gap-4">
          <div><p className="text-[10px] font-semibold uppercase text-slate-400">Callback URL</p><p className="mt-1 break-all font-mono text-xs text-slate-600">{callbackUrl}</p></div>
          <button type="button" title="Salin callback URL" onClick={() => navigator.clipboard.writeText(callbackUrl)} className="rounded-md border border-slate-200 bg-white p-2 text-slate-500"><Copy size={16} /></button>
        </div>
        <button disabled={form.processing} className="mt-7 inline-flex h-11 items-center gap-2 rounded-md bg-slate-950 px-5 text-sm font-semibold text-white disabled:opacity-50"><Save size={16} /> Simpan konfigurasi</button>
      </form>
    </>
  );
}

function DatabasePanel({ database, backups, maxRestoreSizeKb }: { database: DatabaseStatus; backups: Backup[]; maxRestoreSizeKb: number }) {
  const backupForm = useForm({ password: '' });
  const restoreForm = useForm<{ backup: File | null; password: string; confirmation: string }>({ backup: null, password: '', confirmation: '' });
  const deleteForm = useForm({ password: '', confirmation: '' });

  function createBackup(event: FormEvent) {
    event.preventDefault();
    backupForm.post('/superadmin/database/backups', { preserveScroll: true, onSuccess: () => backupForm.reset() });
  }
  function restore(event: FormEvent) {
    event.preventDefault();
    restoreForm.post('/superadmin/database/restore', { forceFormData: true });
  }
  function destroy(event: FormEvent) {
    event.preventDefault();
    if (!window.confirm('Database akan dibackup lalu dihapus seluruhnya. Lanjutkan?')) return;
    deleteForm.delete('/superadmin/database');
  }

  return (
    <>
      <Title eyebrow="Data operations" title="Pemeliharaan database" text="Backup disimpan di storage privat dan tidak dapat diakses tanpa autentikasi Super Admin." />
      <div className="mt-7 grid gap-4 sm:grid-cols-3">
        <Stat icon={<Database size={18} />} label="Nama database" value={database.database} detail={database.driver.toUpperCase()} tone="cyan" />
        <Stat icon={<HardDrive size={18} />} label="Ukuran" value={database.size === null ? 'Tidak diketahui' : formatBytes(database.size)} detail={database.exists ? 'Database tersedia' : 'Database hilang'} tone="green" />
        <Stat icon={<FileArchive size={18} />} label="Backup" value={String(backups.length)} detail="File privat tersimpan" tone="amber" />
      </div>

      <section className="mt-8 border-t border-slate-200 pt-7">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
          <div><h2 className="text-base font-semibold">Backup database</h2><p className="mt-1 text-xs text-slate-400">MySQL dump konsisten dibuat menggunakan transaksi tunggal.</p></div>
          <form onSubmit={createBackup} className="flex gap-2">
            <input type="password" className="control w-52" placeholder="Kata sandi Super Admin" value={backupForm.data.password} onChange={(event) => backupForm.setData('password', event.target.value)} />
            <button title="Buat backup" disabled={backupForm.processing} className="rounded-md bg-slate-950 px-4 text-white disabled:opacity-50"><FileArchive size={17} /></button>
          </form>
        </div>
        {backupForm.errors.password && <p className="mt-2 text-right text-xs text-rose-600">{backupForm.errors.password}</p>}
        <div className="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
          {backups.map((backup) => (
            <div key={backup.filename} className="flex items-center justify-between gap-4 border-b border-slate-100 px-4 py-3 last:border-b-0">
              <div className="min-w-0"><p className="truncate font-mono text-xs text-slate-700">{backup.filename}</p><p className="mt-1 text-[10px] text-slate-400">{formatBytes(backup.size)} / {formatDate(backup.modified_at)}</p></div>
              <a href={`/superadmin/database/backups/${encodeURIComponent(backup.filename)}`} title="Unduh backup" className="p-2 text-cyan-700"><Download size={17} /></a>
            </div>
          ))}
          {!backups.length && <p className="p-7 text-center text-xs text-slate-400">Belum ada backup tersimpan.</p>}
        </div>
      </section>

      <section className="mt-9 border-t border-slate-200 pt-7">
        <h2 className="text-base font-semibold">Restore database</h2>
        <p className="mt-1 text-xs text-slate-400">Backup otomatis dibuat sebelum restore. Sesi akan diakhiri setelah proses selesai.</p>
        <form onSubmit={restore} className="mt-5 grid max-w-3xl gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2"><Field label={`File .sql atau .sqlite (maks. ${Math.round(maxRestoreSizeKb / 1024)} MB)`} error={restoreForm.errors.backup}><input type="file" accept=".sql,.sqlite" className="control file:mr-3 file:border-0 file:bg-slate-100 file:px-3 file:py-1" onChange={(event) => restoreForm.setData('backup', event.target.files?.[0] ?? null)} /></Field></div>
          <Field label="Kata sandi Super Admin" error={restoreForm.errors.password}><input type="password" className="control" value={restoreForm.data.password} onChange={(event) => restoreForm.setData('password', event.target.value)} /></Field>
          <Field label={`Ketik RESTORE ${database.database}`} error={restoreForm.errors.confirmation}><input className="control font-mono" value={restoreForm.data.confirmation} onChange={(event) => restoreForm.setData('confirmation', event.target.value)} /></Field>
          <button disabled={restoreForm.processing} className="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-cyan-700 px-5 text-sm font-semibold text-white disabled:opacity-50 sm:col-span-2"><Upload size={16} /> Restore database</button>
        </form>
      </section>

      <section className="mt-10 border-t-2 border-rose-200 bg-rose-50 px-5 py-7">
        <div className="flex gap-3"><AlertTriangle className="shrink-0 text-rose-600" size={21} /><div><h2 className="font-semibold text-rose-950">Zona berbahaya</h2><p className="mt-1 text-xs leading-5 text-rose-700">Aplikasi membuat backup terakhir, menghapus database, lalu mengarahkan kembali ke setup Super Admin. Semua data aktif akan hilang.</p></div></div>
        <form onSubmit={destroy} className="mt-5 grid max-w-3xl gap-3 sm:grid-cols-[1fr_1fr_auto]">
          <input type="password" className="control border-rose-200" placeholder="Kata sandi Super Admin" value={deleteForm.data.password} onChange={(event) => deleteForm.setData('password', event.target.value)} />
          <input className="control border-rose-200 font-mono" placeholder={`HAPUS ${database.database}`} value={deleteForm.data.confirmation} onChange={(event) => deleteForm.setData('confirmation', event.target.value)} />
          <button disabled={deleteForm.processing} className="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-rose-700 px-4 text-sm font-semibold text-white disabled:opacity-50"><Trash2 size={16} /> Hapus</button>
        </form>
        {(deleteForm.errors.password || deleteForm.errors.confirmation) && <p className="mt-3 text-xs text-rose-700">{deleteForm.errors.password ?? deleteForm.errors.confirmation}</p>}
      </section>
    </>
  );
}

function Nav({ active, icon, label, onClick }: { active: boolean; icon: React.ReactNode; label: string; onClick: () => void }) {
  return <button onClick={onClick} className={`flex shrink-0 items-center gap-3 rounded-md px-3 py-2.5 text-xs font-medium lg:w-full ${active ? 'bg-slate-950 text-white' : 'text-slate-500 hover:bg-slate-100'}`}>{icon}{label}</button>;
}
function Title({ eyebrow, title, text }: { eyebrow: string; title: string; text: string }) {
  return <div><p className="text-[10px] font-semibold uppercase text-cyan-700">{eyebrow}</p><h1 className="mt-2 text-2xl font-semibold">{title}</h1><p className="mt-2 text-sm text-slate-500">{text}</p></div>;
}
function Stat({ icon, label, value, detail, tone }: { icon: React.ReactNode; label: string; value: string; detail: string; tone: 'cyan' | 'green' | 'amber' | 'red' }) {
  const colors = { cyan: 'bg-cyan-50 text-cyan-700', green: 'bg-emerald-50 text-emerald-700', amber: 'bg-amber-50 text-amber-700', red: 'bg-rose-50 text-rose-700' };
  return <article className="rounded-md border border-slate-200 bg-white p-5"><span className={`flex size-9 items-center justify-center rounded-md ${colors[tone]}`}>{icon}</span><p className="mt-5 text-[10px] uppercase text-slate-400">{label}</p><p className="mt-1 truncate text-lg font-semibold">{value}</p><p className="mt-1 truncate text-xs text-slate-400">{detail}</p></article>;
}
function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return <label className="block"><span className="mb-2 block text-xs font-medium text-slate-600">{label}</span>{children}{error && <span className="mt-2 block text-xs text-rose-600">{error}</span>}</label>;
}
function Notice({ tone, text }: { tone: 'success' | 'error'; text: string }) {
  return <div className={`mb-6 flex items-center gap-3 rounded-md border px-4 py-3 text-sm ${tone === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`}>{tone === 'success' ? <CheckCircle2 size={17} /> : <AlertTriangle size={17} />}{text}</div>;
}
function formatBytes(bytes: number) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
}
function formatDate(value: string) {
  return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}
