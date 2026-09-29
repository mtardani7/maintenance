'use client';

import Link from 'next/link';
import { Bell, ChevronLeft, ChevronRight, ClipboardList, Factory, LayoutDashboard, Menu, Settings, Ticket, Wrench, X } from 'lucide-react';
import { usePathname } from 'next/navigation';
import { useState } from 'react';
import { useAuthUser } from './auth-boundary';
import type { Role } from '@/lib/types';

const groups = [
  { label: 'Operasional', links: [
    { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { href: '/incidents', label: 'Insiden', icon: ClipboardList },
    { href: '/tickets', label: 'Tiket', icon: Ticket },
  ] },
  { label: 'Master Data', links: [
    { href: '/machines', label: 'Mesin', icon: Wrench },
    { href: '/plants', label: 'Plant', icon: Factory, roles: ['admin'] as Role[] },
  ] },
  { label: 'Pemantauan', links: [
    { href: '/notifications', label: 'Notifikasi', icon: Bell },
    { href: '/reports', label: 'Laporan', icon: ClipboardList, roles: ['supervisor', 'qa', 'admin'] as Role[] },
  ] },
  { label: 'Sistem', links: [{ href: '/settings', label: 'Pengaturan', icon: Settings, roles: ['admin'] as Role[] }] },
];

export function Navigation() {
  const pathname = usePathname();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const user = useAuthUser();
  const role: Role = user?.role ?? 'operator';

  return (
    <>
      <button className="mobile-menu-button" type="button" onClick={() => setMobileOpen(true)} aria-label="Buka navigasi" aria-expanded={mobileOpen} aria-controls="main-navigation"><Menu aria-hidden="true" /></button>
      {mobileOpen && <button className="mobile-scrim" onClick={() => setMobileOpen(false)} aria-label="Tutup navigasi" />}
      <aside id="main-navigation" className={`sidebar ${mobileOpen ? 'sidebar--open' : ''} ${collapsed ? 'sidebar--collapsed' : ''}`}>
        <div className="brand-lockup">
          <img src={`${process.env.NEXT_PUBLIC_BASE_PATH ?? ''}/qa-logo.png`} alt="Sistem QA" className="brand-mark size-9 object-contain" />
          <div className="brand-copy"><strong>Maintenance System</strong></div>
          <button className="sidebar-toggle" type="button" onClick={() => setCollapsed((value) => !value)} aria-label={collapsed ? 'Tampilkan bilah sisi' : 'Sembunyikan bilah sisi'}>{collapsed ? <ChevronRight aria-hidden="true" /> : <ChevronLeft aria-hidden="true" />}</button>
          <button className="mobile-close" type="button" onClick={() => setMobileOpen(false)} aria-label="Tutup navigasi"><X aria-hidden="true" /></button>
        </div>
        <nav aria-label="Navigasi utama">
          {groups.map((group) => <div className="nav-group" key={group.label}><div className={`nav-label ${collapsed ? 'nav-label--collapsed' : ''}`}>{collapsed ? '•' : group.label}</div>{group.links.filter((link) => !link.roles || link.roles.includes(role)).map((link) => {
            const active = pathname === link.href || (link.href !== '/dashboard' && pathname.startsWith(link.href));
            const Icon = link.icon;
            return <Link key={link.href} href={link.href} onClick={() => setMobileOpen(false)} title={collapsed ? link.label : undefined} className={`nav-link ${active ? 'nav-link--active' : ''}`}><Icon className="nav-icon" aria-hidden="true" /><span className="nav-link-copy">{link.label}</span></Link>;
          })}</div>)}
        </nav>
        <div className="sidebar-foot"><div className="account-card"><span className="account-avatar">{user?.name?.slice(0, 2).toUpperCase() || 'SP'}</span><div><strong>{user?.name || 'Pengguna'}</strong><small>{user?.role || 'Pengguna terautentikasi'}</small></div></div></div>
      </aside>
    </>
  );
}
