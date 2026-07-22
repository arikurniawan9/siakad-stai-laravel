import { router } from '@inertiajs/react';
import { RefreshCw, ShieldCheck } from 'lucide-react';
import type { ChangeEvent } from 'react';

type Props = {
  captcha: { svg: string; expiresAt: string };
  value: string;
  error?: string;
  onChange: (value: string) => void;
  compact?: boolean;
};

export function CaptchaWidget({ captcha, value, error, onChange, compact = false }: Props) {
  function handleChange(event: ChangeEvent<HTMLInputElement>) {
    onChange(event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6));
  }

  function refresh() {
    router.reload({ only: ['captcha'] });
    onChange('');
  }

  return <div className={compact ? 'space-y-2' : 'space-y-3'}>
    <div className={`flex items-center gap-2 font-semibold text-slate-600 ${compact ? 'text-[11px]' : 'text-xs'}`}><ShieldCheck size={compact ? 13 : 15} className="text-teal-600" /> Masukkan kode keamanan</div>
    <div className={compact ? 'flex items-stretch gap-2' : 'flex items-stretch gap-3'}><div className={`aspect-[300/82] min-w-0 flex-1 overflow-hidden rounded-xl border border-slate-200 bg-white ${compact ? 'max-h-14 p-0.5' : 'p-1'}`} dangerouslySetInnerHTML={{ __html: captcha.svg }} /><button type="button" onClick={refresh} title="Buat kode baru" className={`flex shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:border-teal-300 hover:bg-teal-50 hover:text-teal-700 ${compact ? 'w-9' : 'w-11'}`}><RefreshCw size={compact ? 15 : 17} /></button></div>
    <input value={value} onChange={handleChange} inputMode="text" autoComplete="off" maxLength={6} aria-label="Kode CAPTCHA 6 karakter" className={`w-full rounded-xl border border-slate-200 bg-white text-center font-mono font-bold uppercase text-slate-900 outline-none transition placeholder:tracking-normal placeholder:font-normal focus:border-teal-400 focus:ring-4 focus:ring-teal-400/10 ${compact ? 'px-3 py-2 text-sm tracking-[.34em] placeholder:text-xs' : 'px-4 py-3 text-lg tracking-[.42em] placeholder:text-sm'}`} placeholder="KETIK 6 KARAKTER" />
    {error && <p className="text-xs font-medium text-rose-600">{error}</p>}
  </div>;
}
