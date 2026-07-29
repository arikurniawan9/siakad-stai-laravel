import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Database, KeyRound, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';

type Props = {
  defaults: { name: string; username: string; email: string };
  database: { driver: string; database: string; exists: boolean };
  databaseRecreated: boolean;
};

export default function SuperAdminSetup({ defaults, database, databaseRecreated }: Props) {
  const form = useForm({
    name: defaults.name,
    username: defaults.username,
    email: defaults.email,
    password: '',
    password_confirmation: '',
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/superadmin/setup');
  }

  return (
    <>
      <Head title="Setup Super Admin" />
      <main className="min-h-screen overflow-x-hidden bg-[#07111f] text-white">
        <div className="mx-auto grid min-h-screen min-w-0 max-w-6xl lg:grid-cols-[.8fr_1.2fr]">
          <section className="flex min-w-0 flex-col justify-between border-b border-white/10 px-6 py-8 lg:border-b-0 lg:border-r lg:px-10 lg:py-12">
            <a href="/" className="flex items-center gap-3 text-sm font-semibold">
              <span className="flex size-10 items-center justify-center rounded-lg bg-cyan-300 text-slate-950">
                <ShieldCheck size={21} />
              </span>
              SIAKAD.OS
            </a>
            <div className="py-14 lg:py-0">
              <p className="text-xs font-semibold uppercase text-cyan-300">First-run control plane</p>
              <h1 className="mt-4 max-w-md break-words text-3xl font-semibold leading-tight sm:text-4xl">Buat akses pemilik sistem.</h1>
              <p className="mt-5 max-w-md text-sm leading-7 text-slate-400">
                Akun ini terpisah dari administrator akademik dan khusus digunakan untuk konfigurasi integrasi serta pemeliharaan database.
              </p>
            </div>
            <div className="flex items-center gap-3 border-t border-white/10 pt-6 text-xs text-slate-400">
              <Database size={16} className="text-cyan-300" />
              <span>{database.driver.toUpperCase()} / {database.database}</span>
            </div>
          </section>

          <section className="flex min-w-0 items-center px-6 py-10 lg:px-16">
            <div className="min-w-0 w-full max-w-xl">
              {databaseRecreated && (
                <div className="mb-6 rounded-md border border-amber-300/30 bg-amber-300/10 px-4 py-3 text-sm text-amber-100">
                  Database telah dibuat ulang. Buat akun Super Admin baru untuk melanjutkan.
                </div>
              )}
              <div className="mb-8 flex items-start gap-4">
                <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-white/5 text-cyan-300 ring-1 ring-white/10">
                  <KeyRound size={20} />
                </span>
                <div>
                  <h2 className="text-xl font-semibold">Setup Super Admin</h2>
                  <p className="mt-1 text-sm text-slate-400">Setup ditutup otomatis setelah akun pertama berhasil dibuat.</p>
                </div>
              </div>

              <form onSubmit={submit} className="grid gap-5 sm:grid-cols-2">
                <Field label="Nama lengkap" error={form.errors.name}>
                  <input className="super-input" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoFocus />
                </Field>
                <Field label="Username" error={form.errors.username}>
                  <input className="super-input" value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} />
                </Field>
                <div className="sm:col-span-2">
                  <Field label="Email" error={form.errors.email}>
                    <input type="email" className="super-input" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />
                  </Field>
                </div>
                <Field label="Kata sandi" error={form.errors.password}>
                  <input type="password" className="super-input" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
                </Field>
                <Field label="Ulangi kata sandi">
                  <input type="password" className="super-input" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} />
                </Field>
                <p className="text-xs leading-5 text-slate-500 sm:col-span-2">Minimal 12 karakter dengan huruf besar, huruf kecil, angka, dan simbol.</p>
                <button disabled={form.processing} className="flex h-12 items-center justify-center gap-2 rounded-md bg-cyan-300 px-5 text-sm font-semibold text-slate-950 disabled:opacity-50 sm:col-span-2">
                  Buat Super Admin <ArrowRight size={17} />
                </button>
              </form>
            </div>
          </section>
        </div>
      </main>
    </>
  );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-2 block text-xs font-medium text-slate-300">{label}</span>
      {children}
      {error && <span className="mt-2 block text-xs text-rose-300">{error}</span>}
    </label>
  );
}
