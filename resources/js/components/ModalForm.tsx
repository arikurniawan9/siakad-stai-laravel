import { X } from 'lucide-react';
import { useEffect, useId, useRef, type ReactNode } from 'react';

type Props = {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  icon?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  closeDisabled?: boolean;
};

const widths = { sm: 'sm:max-w-md', md: 'sm:max-w-xl', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl' };

export function ModalForm({ open, onClose, title, description, icon, children, footer, size = 'md', closeDisabled = false }: Props) {
  const titleId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);
  const onCloseRef = useRef(onClose);

  useEffect(() => { onCloseRef.current = onClose; }, [onClose]);

  useEffect(() => {
    if (!open) return;
    const previousOverflow = document.body.style.overflow;
    const previousFocus = document.activeElement as HTMLElement | null;
    document.body.style.overflow = 'hidden';
    window.setTimeout(() => {
      const preferred = dialogRef.current?.querySelector<HTMLElement>('[autofocus], input:not([disabled]), select:not([disabled]), textarea:not([disabled])');
      (preferred ?? dialogRef.current)?.focus();
    }, 0);
    const handleKeyboard = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !closeDisabled) onCloseRef.current();
      if (event.key !== 'Tab' || !dialogRef.current) return;
      const focusable = Array.from(dialogRef.current.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'));
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', handleKeyboard);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', handleKeyboard);
      previousFocus?.focus();
    };
  }, [open, closeDisabled]);

  if (!open) return null;

  return <div className="fixed inset-0 z-[100] flex items-end justify-center p-0 sm:items-center sm:p-5" role="presentation">
    <button type="button" aria-label="Tutup modal" onClick={() => !closeDisabled && onClose()} className="absolute inset-0 cursor-default bg-[#03111d]/70 backdrop-blur-[6px] motion-safe:animate-[fade-in_.18s_ease-out]" />
    <div ref={dialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby={titleId} className={`relative flex max-h-[94dvh] w-full flex-col overflow-hidden rounded-t-[1.75rem] bg-white shadow-[0_30px_90px_rgba(2,15,25,.38)] outline-none ring-1 ring-white/30 motion-safe:animate-[modal-rise_.24s_cubic-bezier(.2,.8,.2,1)] sm:rounded-[1.75rem] ${widths[size]}`}>
      <div className="relative overflow-hidden bg-gradient-to-br from-[#071827] via-[#0a2b38] to-[#0d4850] px-5 py-5 text-white sm:px-7 sm:py-6">
        <div className="absolute -right-12 -top-16 size-40 rounded-full bg-teal-300/15 blur-2xl" />
        <div className="absolute bottom-0 left-20 h-px w-48 bg-gradient-to-r from-transparent via-teal-300/70 to-transparent" />
        <div className="relative flex items-start gap-4">
          {icon && <div className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-teal-300/15 text-teal-200 ring-1 ring-teal-200/20">{icon}</div>}
          <div className="min-w-0 flex-1"><h2 id={titleId} className="text-lg font-semibold tracking-tight sm:text-xl">{title}</h2>{description && <p className="mt-1.5 max-w-2xl text-xs leading-5 text-slate-300 sm:text-sm">{description}</p>}</div>
          <button type="button" onClick={onClose} disabled={closeDisabled} className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-slate-300 transition hover:bg-white/20 hover:text-white disabled:opacity-40" aria-label="Tutup"><X size={17} /></button>
        </div>
      </div>
      <div className="modal-scroll min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-7 sm:py-6">{children}</div>
      {footer && <div className="border-t border-slate-100 bg-slate-50/90 px-5 py-4 backdrop-blur sm:px-7">{footer}</div>}
    </div>
  </div>;
}
