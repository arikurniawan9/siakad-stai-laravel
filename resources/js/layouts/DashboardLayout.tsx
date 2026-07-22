import { Link, router, usePage } from '@inertiajs/react';
import {
  ArrowLeftRight, BadgeCheck, Bell, BookOpenCheck, CalendarDays, ChartNoAxesCombined, ChevronDown, ChevronLeft,
  ChevronRight, ClipboardList, Database, FileBarChart, GraduationCap, LayoutDashboard, LogOut,
  Menu as MenuIcon, PanelsTopLeft, ReceiptText, Search, Settings2, Sparkles, UserPlus, Users,
  WalletCards, X, Presentation, Star, ShieldCheck, ClipboardCheck, FileCheck2, ConciergeBell, HeartPulse,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ComponentType, type PropsWithChildren } from 'react';
import type { LucideProps } from 'lucide-react';
import type { MenuItem, SharedProps } from '@/types';

const icons: Record<string, ComponentType<LucideProps>> = {
  ArrowLeftRight, BadgeCheck, BookOpenCheck, ChartNoAxesCombined, ClipboardList, Database,
  FileBarChart, GraduationCap, LayoutDashboard, PanelsTopLeft, ReceiptText, Settings2,
  UserPlus, Users, WalletCards, Presentation, Star, ShieldCheck, ClipboardCheck, FileCheck2, ConciergeBell, HeartPulse, CalendarDays,
};

const iconThemes: Record<string, { icon: string; glow: string; active: string }> = {
  dashboard: { icon: 'text-cyan-300', glow: 'bg-cyan-400/15 ring-cyan-300/20', active: 'from-cyan-400/20 to-blue-500/10' },
  layanan: { icon: 'text-teal-300', glow: 'bg-teal-400/15 ring-teal-300/20', active: 'from-teal-400/20 to-emerald-500/10' },
  bimbingan: { icon: 'text-pink-300', glow: 'bg-pink-400/15 ring-pink-300/20', active: 'from-pink-400/20 to-rose-500/10' },
  akademik: { icon: 'text-sky-300', glow: 'bg-sky-400/15 ring-sky-300/20', active: 'from-sky-400/20 to-indigo-500/10' },
  pmb: { icon: 'text-violet-300', glow: 'bg-violet-400/15 ring-violet-300/20', active: 'from-violet-400/20 to-fuchsia-500/10' },
  keuangan: { icon: 'text-amber-300', glow: 'bg-amber-400/15 ring-amber-300/20', active: 'from-amber-400/20 to-orange-500/10' },
  lms: { icon: 'text-rose-300', glow: 'bg-rose-400/15 ring-rose-300/20', active: 'from-rose-400/20 to-pink-500/10' },
  laporan: { icon: 'text-emerald-300', glow: 'bg-emerald-400/15 ring-emerald-300/20', active: 'from-emerald-400/20 to-teal-500/10' },
  pengaturan: { icon: 'text-slate-300', glow: 'bg-slate-400/15 ring-slate-300/20', active: 'from-slate-300/15 to-slate-500/10' },
};

const fallbackTheme = { icon: 'text-teal-300', glow: 'bg-teal-400/15 ring-teal-300/20', active: 'from-teal-400/20 to-cyan-500/10' };

export function DashboardLayout({ children }: PropsWithChildren) {
  const { auth, navigation, notifications } = usePage<SharedProps>().props;
  const page = usePage();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const [open, setOpen] = useState<string | null>(null);
  const [menuQuery, setMenuQuery] = useState('');
  const user = auth.user;
  const currentPath = normalizePath(page.url);
  const activeHref = useMemo(() => findActiveHref(navigation, currentPath), [navigation, currentPath]);
  const filteredNavigation = useMemo(() => filterNavigation(navigation, menuQuery), [navigation, menuQuery]);

  useEffect(() => {
    const stored = window.localStorage.getItem('siakad.sidebar.collapsed');
    setCollapsed(stored === 'true');
  }, []);

  useEffect(() => {
    const activeParent = navigation.find((item) => containsHref(item, activeHref));
    if (activeParent?.children.length) setOpen(activeParent.key);
    setMobileOpen(false);
  }, [activeHref, navigation]);

  useEffect(() => {
    if (!menuQuery.trim()) return;
    const firstParent = filteredNavigation.find((item) => item.children.length > 0);
    if (firstParent) setOpen(firstParent.key);
  }, [filteredNavigation, menuQuery]);

  function toggleCollapsed() {
    setCollapsed((value) => {
      window.localStorage.setItem('siakad.sidebar.collapsed', String(!value));
      return !value;
    });
  }

  function expandSidebar() {
    setCollapsed(false);
    window.localStorage.setItem('siakad.sidebar.collapsed', 'false');
  }

  function searchMenu(value: string) {
    setMenuQuery(value);
    if (value.trim()) setCollapsed(false);
  }

  return <div className="min-h-screen bg-[#f5f7fb] text-slate-900">
    <aside className={`sidebar-surface fixed inset-y-0 left-0 z-40 flex w-72 flex-col overflow-hidden border-r border-white/10 bg-[#07111f] py-5 text-slate-300 shadow-2xl shadow-slate-950/20 transition-[width,transform,padding] duration-300 ease-out lg:translate-x-0 ${collapsed ? 'lg:w-20 lg:px-3' : 'px-5'} ${mobileOpen ? 'translate-x-0 px-5' : '-translate-x-full'}`}>
      <div aria-hidden className="pointer-events-none absolute inset-0 overflow-hidden"><div className="absolute -left-16 top-8 size-48 rounded-full bg-cyan-400/10 blur-3xl" /><div className="absolute -right-20 top-1/3 size-56 rounded-full bg-violet-500/10 blur-3xl" /><div className="sidebar-accent-line absolute inset-x-0 top-0 h-px" /></div>

      <div className="relative flex h-11 shrink-0 items-center justify-between px-1">
        <Link href="/dashboard" className="group flex min-w-0 items-center gap-3 text-white">
          <span className="relative flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-cyan-300 via-teal-300 to-blue-400 font-black text-slate-950 shadow-lg shadow-cyan-500/20 ring-1 ring-white/20"><span className="relative z-10">S</span><span className="absolute inset-1 rounded-xl bg-white/15" /></span>
          <span className={`min-w-0 whitespace-nowrap font-semibold tracking-tight transition-opacity duration-200 ${collapsed ? 'lg:pointer-events-none lg:opacity-0' : 'opacity-100'}`}>SIAKAD<span className="text-cyan-300">.OS</span><span className="mt-0.5 block text-[8px] font-bold uppercase tracking-[.24em] text-slate-500">Academic suite</span></span>
        </Link>
        <button type="button" aria-label="Tutup menu" onClick={() => setMobileOpen(false)} className="rounded-xl p-2 hover:bg-white/10 lg:hidden"><X size={18} /></button>
      </div>

      <div className="relative mt-7 flex min-h-0 flex-1 flex-col">
        <div className={`mb-3 flex items-center px-3 transition-opacity ${collapsed ? 'lg:justify-center lg:px-0' : ''}`}><p className={`text-[9px] font-bold uppercase tracking-[.24em] text-slate-500 ${collapsed ? 'lg:hidden' : ''}`}>{menuQuery ? 'Hasil pencarian' : 'Workspace'}</p>{collapsed && <Sparkles size={14} className="hidden text-cyan-300 lg:block" />}</div>
        <nav aria-label="Navigasi utama" className="sidebar-scroll min-h-0 flex-1 space-y-1.5 overflow-y-auto overscroll-contain scroll-smooth pr-1">
          {filteredNavigation.map((item) => <SidebarItem key={item.key} item={item} activeHref={activeHref} open={open} collapsed={collapsed} setOpen={setOpen} expandSidebar={expandSidebar} />)}
          {filteredNavigation.length === 0 && <div className={`rounded-xl border border-white/10 bg-white/5 p-4 text-center text-xs text-slate-500 ${collapsed ? 'lg:hidden' : ''}`}><Search size={17} className="mx-auto mb-2 text-slate-600" />Menu tidak ditemukan.</div>}
        </nav>
      </div>

      <div className={`relative mt-4 shrink-0 rounded-xl border border-white/10 bg-white/[.045] p-3 shadow-inner shadow-white/[.02] backdrop-blur transition-all ${collapsed ? 'lg:p-2' : ''}`}>
        <div className={`flex items-center gap-3 ${collapsed ? 'lg:justify-center lg:gap-0' : ''}`}>
          <div className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-violet-300 via-cyan-300 to-teal-300 text-sm font-black text-slate-950 shadow-lg shadow-cyan-500/10">{user?.name.slice(0, 1).toUpperCase()}</div>
          <div className={`min-w-0 flex-1 transition-opacity ${collapsed ? 'lg:hidden' : ''}`}><p className="truncate text-sm font-medium text-white">{user?.name}</p><p className="truncate text-[10px] font-semibold uppercase tracking-wider text-cyan-300/70">{user?.activeRole ?? 'User'}</p></div>
          <button type="button" onClick={() => router.post('/logout')} title="Keluar" className={`rounded-xl p-2 text-slate-500 hover:bg-rose-400/10 hover:text-rose-300 ${collapsed ? 'lg:hidden' : ''}`}><LogOut size={15} /></button>
        </div>
      </div>
    </aside>

    <button type="button" onClick={toggleCollapsed} aria-label={collapsed ? 'Tampilkan sidebar' : 'Sembunyikan sidebar'} title={collapsed ? 'Tampilkan sidebar' : 'Sembunyikan sidebar'} className={`fixed top-[3.85rem] z-50 hidden size-9 items-center justify-center rounded-xl border border-white/70 bg-gradient-to-br from-white to-slate-100 text-slate-700 shadow-[0_10px_30px_rgba(15,23,42,.18)] ring-1 ring-slate-200 transition-[left,transform,box-shadow] duration-300 hover:-translate-y-0.5 hover:text-teal-600 hover:shadow-[0_14px_34px_rgba(13,148,136,.2)] lg:flex ${collapsed ? 'left-[4.35rem]' : 'left-[17.35rem]'}`}>{collapsed ? <ChevronRight size={17} /> : <ChevronLeft size={17} />}<span className="absolute -bottom-1 -right-1 size-2.5 rounded-full border-2 border-white bg-teal-400" /></button>

    {mobileOpen && <button aria-label="Tutup menu" onClick={() => setMobileOpen(false)} className="fixed inset-0 z-30 bg-slate-950/60 backdrop-blur-sm lg:hidden" />}

    <div className={`transition-[padding] duration-300 ease-out ${collapsed ? 'lg:pl-20' : 'lg:pl-72'}`}>
      <header className="sticky top-0 z-20 flex min-h-20 items-center justify-between gap-4 border-b border-slate-200/70 bg-[#f5f7fb]/85 px-5 py-3 shadow-sm shadow-slate-900/[.025] backdrop-blur-xl lg:px-10">
        <div className="flex min-w-0 items-center gap-3"><button type="button" onClick={() => setMobileOpen(true)} aria-label="Buka menu" className="rounded-xl bg-white p-2.5 text-slate-600 shadow-sm ring-1 ring-slate-200 hover:text-teal-600 lg:hidden"><MenuIcon size={20} /></button><div className="relative hidden sm:block"><Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input value={menuQuery} onChange={(event) => searchMenu(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { const href = firstNavigableHref(filteredNavigation); if (href) router.visit(href); } }} className="w-72 rounded-xl border-0 bg-white py-2.5 pl-9 pr-9 text-sm outline-none ring-1 ring-slate-200 placeholder:text-slate-400 focus:ring-2 focus:ring-teal-300" placeholder="Cari menu..." aria-label="Cari menu" />{menuQuery && <button type="button" onClick={() => setMenuQuery('')} aria-label="Hapus pencarian" className="absolute right-2 top-1/2 -translate-y-1/2 rounded-xl p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"><X size={14} /></button>}</div></div>
        <div className="flex items-center gap-3"><RoleSwitcher roles={user?.roles ?? []} activeRole={user?.activeRole ?? ''} /><Link href="/notifications" title="Notifikasi" className="relative rounded-xl bg-white p-2.5 text-slate-500 shadow-sm ring-1 ring-slate-200 hover:-translate-y-0.5 hover:text-slate-900"><Bell size={18} />{notifications.unreadCount > 0 && <span className="absolute -right-1.5 -top-1.5 flex min-w-5 items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-[9px] font-bold text-white ring-2 ring-white">{notifications.unreadCount > 99 ? '99+' : notifications.unreadCount}</span>}</Link><div className="hidden h-8 w-px bg-slate-200 sm:block" /><span className="hidden text-right sm:block"><span className="block text-sm font-semibold">{user?.name}</span><span className="block text-xs text-slate-400">{user?.activeRole}</span></span><div className="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-slate-800 to-slate-950 text-sm font-bold text-white shadow-lg shadow-slate-900/10">{user?.name.slice(0, 1).toUpperCase()}</div></div>
      </header>
      <main className="mx-auto max-w-[1600px] p-5 lg:p-10">{children}</main>
    </div>
  </div>;
}

function SidebarItem({ item, activeHref, open, collapsed, setOpen, expandSidebar }: { item: MenuItem; activeHref: string | null; open: string | null; collapsed: boolean; setOpen: (key: string | null) => void; expandSidebar: () => void }) {
  const Icon = (item.icon && icons[item.icon]) || LayoutDashboard;
  const hasChildren = item.children.length > 0;
  const expanded = open === item.key;
  const active = item.href === activeHref;
  const descendantActive = containsHref(item, activeHref);
  const navigable = isNavigable(item.href);
  const themeKey = item.key.split('.')[0];
  const theme = iconThemes[themeKey] ?? fallbackTheme;
  const activeState = active || descendantActive;
  const commonClass = `group relative flex w-full items-center justify-between rounded-xl px-2.5 py-2.5 text-sm transition-all duration-200 ${collapsed ? 'lg:justify-center lg:px-2' : ''} ${activeState ? `bg-gradient-to-r ${theme.active} font-semibold text-white shadow-[inset_0_1px_0_rgba(255,255,255,.05)]` : navigable || hasChildren ? 'text-slate-400 hover:bg-white/[.055] hover:text-white' : 'cursor-not-allowed text-slate-600'}`;

  const content = <><span className={`flex min-w-0 items-center gap-3 ${collapsed ? 'lg:justify-center lg:gap-0' : ''}`}><span className={`flex size-8 shrink-0 items-center justify-center rounded-xl ring-1 transition-all duration-200 ${theme.glow} ${activeState ? 'scale-105 shadow-lg shadow-black/10' : 'group-hover:scale-105'}`}><Icon size={16} strokeWidth={activeState ? 2.3 : 1.9} className={theme.icon} /></span><span className={`truncate transition-opacity duration-200 ${collapsed ? 'lg:hidden' : ''}`}>{item.label}</span></span><span className={`ml-2 flex items-center gap-1 ${collapsed ? 'lg:hidden' : ''}`}>{!navigable && !hasChildren && <span className="rounded-xl border border-white/10 px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-wider text-slate-600">Segera</span>}{hasChildren && <ChevronDown size={14} className={`transition-transform duration-300 ${expanded ? 'rotate-180 text-white' : ''}`} />}</span>{activeState && <span className="absolute -left-px top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-full bg-gradient-to-b from-cyan-300 to-teal-400 shadow-[0_0_12px_rgba(45,212,191,.8)]" />}</>;

  function toggleGroup() {
    if (!hasChildren) return;
    if (collapsed) expandSidebar();
    setOpen(expanded ? null : item.key);
  }

  return <div>
    {hasChildren ? <button type="button" onClick={toggleGroup} aria-expanded={expanded} title={collapsed ? item.label : undefined} className={commonClass}>{content}</button> : navigable ? <Link href={item.href!} aria-current={active ? 'page' : undefined} title={collapsed ? item.label : undefined} className={commonClass}>{content}</Link> : <button type="button" disabled title={`${item.label} - segera hadir`} className={commonClass}>{content}</button>}
    {hasChildren && <div className={`grid transition-[grid-template-rows,opacity] duration-300 ease-out ${expanded && !collapsed ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}><div className="overflow-hidden"><div className="relative ml-4 space-y-1 py-2 pl-5 before:absolute before:inset-y-2 before:left-0 before:w-px before:bg-gradient-to-b before:from-white/5 before:via-white/15 before:to-transparent">{item.children.map((child) => <SidebarChild key={child.key} item={child} activeHref={activeHref} theme={theme} />)}</div></div></div>}
  </div>;
}

function SidebarChild({ item, activeHref, theme }: { item: MenuItem; activeHref: string | null; theme: { icon: string; glow: string; active: string } }) {
  const Icon = (item.icon && icons[item.icon]) || ChevronRight;
  const active = item.href === activeHref;
  const navigable = isNavigable(item.href);
  const content = <><span className={`flex size-6 shrink-0 items-center justify-center rounded-xl ${active ? `${theme.glow} ring-1` : 'bg-white/[.035]'}`}><Icon size={12} className={active ? theme.icon : 'text-slate-600'} /></span><span className="min-w-0 flex-1 truncate">{item.label}</span>{!navigable && <span className="text-[8px] font-bold uppercase tracking-wider text-slate-700">Segera</span>}{active && <span className="size-1.5 rounded-full bg-cyan-300 shadow-[0_0_8px_rgba(103,232,249,.8)]" />}</>;
  const className = `flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-[11px] transition-all ${active ? 'bg-white/[.08] font-semibold text-white' : navigable ? 'text-slate-500 hover:translate-x-0.5 hover:bg-white/[.045] hover:text-slate-200' : 'cursor-not-allowed text-slate-700'}`;

  return navigable ? <Link href={item.href!} aria-current={active ? 'page' : undefined} className={className}>{content}</Link> : <button type="button" disabled className={`${className} w-full text-left`}>{content}</button>;
}

function RoleSwitcher({ roles, activeRole }: { roles: string[]; activeRole: string }) {
  if (roles.length < 2) return null;
  return <label className="hidden items-center gap-2 rounded-xl bg-white px-3 py-2 text-xs font-semibold text-slate-600 shadow-sm ring-1 ring-slate-200 md:flex"><ArrowLeftRight size={14} className="text-teal-600" /><select value={activeRole} onChange={(event) => router.post('/role/switch', { role: event.target.value }, { preserveScroll: true })} className="max-w-28 bg-transparent outline-none"><option value={activeRole}>{activeRole}</option>{roles.filter((role) => role !== activeRole).map((role) => <option key={role} value={role}>{role}</option>)}</select></label>;
}

function normalizePath(url: string): string {
  const path = url.split('?')[0].replace(/\/$/, '');
  return path || '/';
}

function isNavigable(href: string | null): href is string {
  return Boolean(href && href !== '#');
}

function allHrefs(items: MenuItem[]): string[] {
  return items.flatMap((item) => [...(isNavigable(item.href) ? [normalizePath(item.href)] : []), ...allHrefs(item.children)]);
}

function findActiveHref(items: MenuItem[], currentPath: string): string | null {
  return allHrefs(items).filter((href) => currentPath === href || (href !== '/' && currentPath.startsWith(`${href}/`))).sort((a, b) => b.length - a.length)[0] ?? null;
}

function containsHref(item: MenuItem, href: string | null): boolean {
  return Boolean(href && (item.href === href || item.children.some((child) => containsHref(child, href))));
}

function filterNavigation(items: MenuItem[], query: string): MenuItem[] {
  const term = query.trim().toLocaleLowerCase('id-ID');
  if (!term) return items;
  return items.flatMap((item) => {
    if (item.label.toLocaleLowerCase('id-ID').includes(term)) return [item];
    const children = filterNavigation(item.children, term);
    return children.length ? [{ ...item, children }] : [];
  });
}

function firstNavigableHref(items: MenuItem[]): string | null {
  for (const item of items) {
    if (isNavigable(item.href)) return item.href;
    const childHref = firstNavigableHref(item.children);
    if (childHref) return childHref;
  }
  return null;
}
