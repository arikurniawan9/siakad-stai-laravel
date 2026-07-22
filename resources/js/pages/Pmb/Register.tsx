import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CheckCircle2, Eye, EyeOff, GraduationCap, ShieldCheck, Sparkles } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { CaptchaWidget } from '@/components/CaptchaWidget';

type Program = { id: number; name: string; code: string };
type Props = { programs: Program[]; captcha: { svg: string; expiresAt: string } };

export default function Register({ programs, captcha }: Props) {
  const [showPassword, setShowPassword] = useState(false);
  const form = useForm({ full_name: '', email: '', phone: '', program_id: '', password: '', password_confirmation: '', captcha: '' });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/pmb/register', { onFinish: () => form.reset('password', 'password_confirmation', 'captcha') });
  }

  return <>
    <Head title="Daftar PMB" />
    <div className="relative min-h-screen overflow-hidden bg-[#effbf7] text-slate-900">
      <div className="pointer-events-none absolute -left-40 top-0 size-[30rem] rounded-full bg-teal-300/25 blur-3xl animate-float-slow" />
      <div className="pointer-events-none absolute -bottom-40 right-0 size-[32rem] rounded-full bg-blue-300/25 blur-3xl animate-float-reverse" />
      <div className="relative mx-auto max-w-7xl px-5 py-6 sm:px-8 lg:px-10">
        <header className="flex items-center justify-between"><Link href="/" className="flex items-center gap-3 text-sm font-bold tracking-tight text-[#071827]"><span className="flex size-10 items-center justify-center rounded-2xl bg-[#071827] font-black text-teal-300">S</span>SIAKAD<span className="text-teal-700">.OS</span></Link><Link href="/login" className="flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700"><ArrowLeft size={16} /> Sudah punya akun?</Link></header>
        <main className="mx-auto grid max-w-6xl gap-8 py-10 lg:grid-cols-[.75fr_1.25fr] lg:py-16">
          <section className="flex flex-col justify-center"><div className="mb-6 inline-flex w-fit items-center gap-2 rounded-full border border-teal-200 bg-white/70 px-3 py-1.5 text-xs font-bold text-teal-800"><Sparkles size={14} /> Penerimaan mahasiswa baru</div><h1 className="max-w-lg text-4xl font-bold leading-tight tracking-[-.055em] text-[#071827] sm:text-5xl">Mulai perjalanan akademikmu.</h1><p className="mt-5 max-w-md text-sm leading-7 text-slate-500">Buat akun PMB untuk mengisi data pendaftaran, memantau verifikasi, dan mendapatkan informasi seleksi.</p><div className="mt-9 space-y-4 text-sm text-slate-600"><Benefit icon={<CheckCircle2 size={16} />} text="Formulir dapat dilanjutkan kapan saja" /><Benefit icon={<GraduationCap size={16} />} text="Pilih program studi sejak awal" /><Benefit icon={<ShieldCheck size={16} />} text="Data dilindungi dan diverifikasi" /></div></section>
          <section className="rounded-[2rem] border border-white/80 bg-white/90 p-6 shadow-2xl shadow-slate-300/40 backdrop-blur-xl sm:p-9"><div className="mb-8"><p className="text-sm font-semibold text-teal-700">Langkah 1 dari 2</p><h2 className="mt-2 text-2xl font-bold tracking-tight text-[#071827]">Buat akun pendaftar</h2><p className="mt-2 text-sm text-slate-500">Isi data utama dengan nama sesuai dokumen resmi.</p></div>
            <form onSubmit={submit} className="space-y-5"><Field label="Nama lengkap" error={form.errors.full_name}><input required value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} className="input" placeholder="Contoh: Nadia Putri" /></Field><div className="grid gap-5 sm:grid-cols-2"><Field label="Email aktif" error={form.errors.email}><input required type="email" autoComplete="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className="input" placeholder="nama@email.com" /></Field><Field label="Nomor WhatsApp" error={form.errors.phone}><input required inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className="input" placeholder="08xxxxxxxxxx" /></Field></div><Field label="Program studi pilihan" error={form.errors.program_id}><select value={form.data.program_id} onChange={(e) => form.setData('program_id', e.target.value)} className="input"><option value="">Pilih program studi</option>{programs.map((program) => <option key={program.id} value={program.id}>{program.name} ({program.code})</option>)}</select></Field><div className="grid gap-5 sm:grid-cols-2"><Field label="Kata sandi" error={form.errors.password}><div className="relative"><input required minLength={12} type={showPassword ? 'text' : 'password'} autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} className="input pr-11" placeholder="Minimal 12 karakter" /><button type="button" onClick={() => setShowPassword(!showPassword)} className="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-slate-400 hover:text-slate-800">{showPassword ? <EyeOff size={16} /> : <Eye size={16} />}</button></div></Field><Field label="Ulangi kata sandi"><input required minLength={12} type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} className="input" placeholder="Ulangi kata sandi" /></Field></div><div className="rounded-2xl border border-slate-200 bg-slate-50/80 p-3"><CaptchaWidget captcha={captcha} value={form.data.captcha} error={form.errors.captcha} onChange={(value) => form.setData('captcha', value)} /></div><button disabled={form.processing} className="group flex w-full items-center justify-center gap-3 rounded-xl bg-[#071827] py-3.5 font-semibold text-white shadow-lg transition hover:bg-[#0d3143] disabled:cursor-wait disabled:opacity-60">{form.processing ? 'Mendaftarkan...' : 'Buat akun PMB'}{!form.processing && <ArrowRight size={17} className="transition group-hover:translate-x-1" />}</button><p className="text-center text-xs leading-5 text-slate-400">Dengan mendaftar, Anda menyetujui proses verifikasi data pendaftaran.</p></form>
          </section>
        </main>
      </div>
    </div>
  </>;
}

function Benefit({ icon, text }: { icon: React.ReactNode; text: string }) { return <div className="flex items-center gap-3"><span className="flex size-8 items-center justify-center rounded-xl bg-teal-100 text-teal-700">{icon}</span>{text}</div>; }
function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) { return <label className="block"><span className="mb-2 block text-sm font-semibold text-slate-700">{label}</span>{children}{error && <p className="mt-2 text-xs text-rose-600">{error}</p>}</label>; }
