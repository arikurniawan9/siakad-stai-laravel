import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, Eye, EyeOff, LockKeyhole, ShieldCheck, UserRound } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { CaptchaWidget } from '@/components/CaptchaWidget';

type Props = { captcha: { svg: string; expiresAt: string } };

export default function Login({ captcha }: Props) {
  const [showPassword, setShowPassword] = useState(false);
  const form = useForm({ identifier: '', password: '', remember: false, captcha: '' });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/login', { onFinish: () => form.reset('password', 'captcha') });
  }

  return <>
    <Head title="Masuk" />
    <main className="relative flex min-h-dvh items-center justify-center overflow-hidden bg-[#edf8f6] px-3 py-3 text-slate-900 sm:px-5">
      <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(rgba(7,24,39,.055)_1px,transparent_1px),linear-gradient(90deg,rgba(7,24,39,.055)_1px,transparent_1px)] bg-[size:34px_34px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]" />
      <div className="pointer-events-none absolute -left-24 -top-24 size-80 rounded-full bg-teal-300/35 blur-3xl animate-float-slow" />
      <div className="pointer-events-none absolute -bottom-28 -right-20 size-96 rounded-full bg-blue-300/30 blur-3xl animate-float-reverse" />
      <div className="pointer-events-none absolute left-1/2 top-1/2 h-72 w-72 -translate-x-1/2 -translate-y-1/2 rotate-12 border border-teal-400/15" />

      <section className="relative w-full max-w-sm overflow-hidden rounded-xl border border-white/90 bg-white/95 shadow-[0_24px_70px_-32px_rgba(7,24,39,.42)] backdrop-blur-xl">
        <div className="h-1 bg-gradient-to-r from-teal-400 via-cyan-400 to-blue-500" />
        <div className="p-5 sm:p-6">
          <div className="mb-4 flex items-center justify-between">
            <Link href="/" className="flex items-center gap-2 text-sm font-bold tracking-tight text-[#071827]"><span className="flex size-8 items-center justify-center rounded-xl bg-[#071827] text-xs font-black text-teal-300 shadow-lg shadow-blue-950/15">S</span><span>SIAKAD<span className="text-teal-600">.OS</span></span></Link>
            <span className="flex items-center gap-1 text-[10px] font-semibold text-teal-700"><ShieldCheck size={12} /> Secure access</span>
          </div>

          <div className="mb-4">
            <p className="text-[10px] font-bold uppercase tracking-[.16em] text-teal-600">Portal autentikasi</p>
            <h1 className="mt-1.5 text-xl font-bold tracking-[-.035em] text-[#071827]">Masuk ke sistem</h1>
            <p className="mt-1 text-xs text-slate-500">Gunakan akun yang telah terdaftar.</p>
          </div>

          <form onSubmit={submit} className="space-y-3">
            <div>
              <label htmlFor="identifier" className="mb-1 block text-xs font-semibold text-slate-700">Username atau email</label>
              <div className="relative"><UserRound size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input id="identifier" required autoFocus autoComplete="username" value={form.data.identifier} onChange={(event) => form.setData('identifier', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-9 pr-3 text-xs outline-none transition placeholder:text-slate-400 focus:border-teal-400 focus:bg-white focus:ring-4 focus:ring-teal-400/10" placeholder="username atau email" /></div>
              {form.errors.identifier && <p className="mt-1.5 text-xs font-medium text-rose-600">{form.errors.identifier}</p>}
            </div>

            <div>
              <label htmlFor="password" className="mb-1 block text-xs font-semibold text-slate-700">Kata sandi</label>
              <div className="relative"><LockKeyhole size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input id="password" required type={showPassword ? 'text' : 'password'} autoComplete="current-password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-9 pr-10 text-xs outline-none transition placeholder:text-slate-400 focus:border-teal-400 focus:bg-white focus:ring-4 focus:ring-teal-400/10" placeholder="Masukkan kata sandi" /><button type="button" onClick={() => setShowPassword((visible) => !visible)} aria-label={showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'} className="absolute right-2 top-1/2 -translate-y-1/2 rounded-xl p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">{showPassword ? <EyeOff size={14} /> : <Eye size={14} />}</button></div>
              {form.errors.password && <p className="mt-1.5 text-xs font-medium text-rose-600">{form.errors.password}</p>}
            </div>

            <div className="rounded-xl border border-slate-200 bg-slate-50 p-2.5"><CaptchaWidget compact captcha={captcha} value={form.data.captcha} error={form.errors.captcha} onChange={(value) => form.setData('captcha', value)} /></div>

            <div className="flex items-center justify-between"><label className="flex cursor-pointer items-center gap-2 text-[11px] font-medium text-slate-500"><input type="checkbox" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} className="size-3.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500" /> Ingat perangkat ini</label><Link href="/forgot-password" className="text-[11px] font-semibold text-teal-700 hover:text-teal-900">Lupa password?</Link></div>

            <button disabled={form.processing} className="group flex w-full items-center justify-center gap-2 rounded-xl bg-[#071827] py-2.5 text-xs font-semibold text-white shadow-lg shadow-blue-950/15 transition hover:bg-[#0d3143] focus:outline-none focus:ring-4 focus:ring-teal-400/20 disabled:cursor-wait disabled:opacity-60">{form.processing ? 'Memverifikasi...' : 'Masuk'}{!form.processing && <ArrowRight size={14} className="transition group-hover:translate-x-1" />}</button>
          </form>

          <p className="mt-4 text-center text-[11px] text-slate-500">Belum memiliki akun PMB? <Link href="/pmb/register" className="font-bold text-teal-700 hover:text-teal-900">Daftar sekarang</Link></p>
        </div>
      </section>
    </main>
  </>;
}
