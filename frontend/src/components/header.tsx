'use client';

import { usePathname } from 'next/navigation';
import Link from 'next/link';
import { Bell, ChevronDown, Home, Moon, UserCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { getUnreadNotificationCount } from '@/lib/notification-api';
import { logout } from '@/lib/auth';
import { getNotifications } from '@/lib/notification-api';
import type { AppNotification } from '@/lib/notification-types';
import { useAuthUser } from './auth-boundary';

const titles: Record<string, string> = {
  '/dashboard': 'Dashboard',
  '/machines': 'Daftar mesin',
  '/incidents': 'Catatan insiden',
  '/tickets': 'Tiket pemeliharaan',
  '/notifications': 'Notifikasi',
  '/reports': 'Laporan',
  '/settings': 'Pengaturan sistem',
};

export function Header() {
  const pathname = usePathname();
  const title = titles[pathname] ?? 'Dashboard';
  const [unread, setUnread] = useState(0);
  const user = useAuthUser();
  const [profileOpen, setProfileOpen] = useState(false);
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const [notifications, setNotifications] = useState<AppNotification[]>([]);
  const [notificationsLoading, setNotificationsLoading] = useState(false);

  useEffect(() => { getUnreadNotificationCount().then(setUnread).catch(() => setUnread(0)); }, [pathname]);

  const initials = user?.name.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase() ?? 'OP';
  async function signOut() { try { await logout(); } finally { window.location.assign('/login'); } }
  async function toggleNotifications() {
    const nextOpen = !notificationsOpen;
    setNotificationsOpen(nextOpen);
    if (nextOpen) {
      setNotificationsLoading(true);
      try { setNotifications((await getNotifications()).data); } catch { setNotifications([]); } finally { setNotificationsLoading(false); }
    }
  }
  return <header className="topbar"><div className="breadcrumb"><Home aria-hidden="true" /><span>/</span><strong>{title}</strong></div><div className="topbar-actions"><div className="notification-menu"><button className="notification-indicator" type="button" onClick={toggleNotifications} aria-label={`${unread} notifikasi belum dibaca`} aria-expanded={notificationsOpen}><Bell aria-hidden="true" />{unread > 0 && <b>{unread > 99 ? '99+' : unread}</b>}</button>{notificationsOpen && <div className="notification-dropdown"><div className="notification-dropdown-header"><strong>Notifikasi</strong><span>{unread} belum dibaca</span></div>{notificationsLoading ? <p className="notification-empty">Memuat notifikasi...</p> : notifications.length === 0 ? <p className="notification-empty">Tidak ada notifikasi</p> : <div className="notification-dropdown-list">{notifications.slice(0, 5).map((item) => <Link href={item.url ?? (item.relatedTicketId ? `/tickets/${item.relatedTicketId}` : '/notifications')} key={item.id} onClick={() => setNotificationsOpen(false)}><strong>{item.title}</strong><span>{item.body}</span></Link>)}</div>}<Link className="notification-dropdown-footer" href="/notifications" onClick={() => setNotificationsOpen(false)}>Lihat semua notifikasi</Link></div>}</div><button className="theme-button" type="button" aria-label="Ganti tema"><Moon aria-hidden="true" /></button><div className="profile-menu"><button className="profile profile-trigger" type="button" onClick={() => setProfileOpen((open) => !open)} aria-expanded={profileOpen} aria-label="Buka menu profil"><UserCircle className="profile-icon" aria-hidden="true" /><span className="profile-avatar">{initials}</span><span className="profile-name">{user?.name ?? 'Memuat pengguna'}</span><ChevronDown className="profile-chevron" aria-hidden="true" /></button>{profileOpen && <div className="profile-dropdown"><strong>{user?.name ?? 'Pengguna'}</strong><small>{user?.role ?? 'Sesi'}</small><button type="button" onClick={signOut}>Keluar</button></div>}</div></div></header>;
}
