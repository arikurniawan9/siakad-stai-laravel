import { Head, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, KeyRound, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';

export default function SuperAdminLogin() {
  const form = useForm({ identifier: '', password: '', remember: false });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/superadmin/login');
  }

  return (
    <>
      <Head title="Super Admin" />
      <main className="flex min-h-screen items-center justify-center bg-[#07111f] px-5 py-12 text-white">
        <div className="w-full max-w-md">
          <a href="/" className="mb-12 inline-flex items-center gap-2 text-xs text-slate-400 hover:text-white">
            <ArrowLeft size={15} /> Kembali ke portal
          </a>
          <div className="mb-8 flex items-center gap-4">
            <span className="flex size-12 items-center justify-center rounded-lg bg-cyan-300 text-slate-950">
              <ShieldCheck size={23} />
            </span>
            <div>
              <p className="text-xs font-semibold uppercase text-cyan-300">SIAKAD.OS</p>
              <h1 className="mt-1 text-2xl font-semibold">Super Admin</h1>
            </div>
          </div>
          <form onSubmit={submit} className="border-y border-white/10 py-8">
            <label className="block">
              <span className="mb-2 block text-xs text-slate-300">Email atau username</span>
              <input autoFocus className="super-input" value={form.data.identifier} onChange={(event) => form.setData('identifier', event.target.value)} />
              {form.errors.identifier && <span className="mt-2 block text-xs text-rose-300">{form.errors.identifier}</span>}
            </label>
            <label className="mt-5 block">
              <span className="mb-2 block text-xs text-slate-300">Kata sandi</span>
              <input type="password" className="super-input" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
              {form.errors.password && <span className="mt-2 block text-xs text-rose-300">{form.errors.password}</span>}
            </label>
            <label className="mt-5 flex items-center gap-2 text-xs text-slate-400">
              <input type="checkbox" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} className="size-4 accent-cyan-300" />
              Pertahankan sesi di perangkat ini
            </label>
            <button disabled={form.processing} className="mt-7 flex h-12 w-full items-center justify-center gap-2 rounded-md bg-cyan-300 text-sm font-semibold text-slate-950 disabled:opacity-50">
              <KeyRound size={17} /> Masuk <ArrowRight size={17} />
            </button>
          </form>
          <p className="mt-6 text-xs leading-5 text-slate-500">Akses ini hanya untuk pemilik sistem. Percobaan login dibatasi dan dicatat oleh aplikasi.</p>
        </div>
      </main>
    </>
  );
}
