import { Link } from '@inertiajs/react';
import { ArrowUpRight, Layers3 } from 'lucide-react';
import type { PropsWithChildren } from 'react';

export function PublicLayout({ children }: PropsWithChildren) {
  return (
    <div className="min-h-screen bg-[#07111f] text-slate-100">
      <header className="mx-auto flex max-w-7xl items-center justify-between px-6 py-6 lg:px-10">
        <Link href="/" className="flex items-center gap-3 font-semibold tracking-tight">
          <span className="flex size-10 items-center justify-center rounded-2xl bg-teal-400 text-slate-950 shadow-[0_0_30px_rgba(45,212,191,.25)]"><Layers3 size={20} /></span>
          <span>SIAKAD<span className="text-teal-300">.OS</span></span>
        </Link>
        <div className="flex items-center gap-3 text-sm">
          <span className="hidden text-slate-400 sm:inline">Portal akademik terpadu</span>
          <Link href="/pmb/register" className="hidden font-medium text-teal-200 transition hover:text-teal-100 sm:inline">Daftar PMB</Link>
          <Link href="/login" className="group flex items-center gap-2 rounded-full border border-white/15 px-4 py-2.5 font-medium transition hover:border-teal-300/60 hover:bg-white/5">Masuk <ArrowUpRight size={16} className="transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5" /></Link>
        </div>
      </header>
      <main>{children}</main>
      <footer className="mx-auto max-w-7xl px-6 py-8 text-xs text-slate-500 lg:px-10">SIAKAD.OS · Fondasi Laravel 13 + Inertia</footer>
    </div>
  );
}
