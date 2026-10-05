'use client';

import { usePathname } from 'next/navigation';
import Link from 'next/link';
import { AlertTriangle, Bell, ChevronDown, Home, UserCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { MouseEvent } from 'react';
import { markNotificationRead, relativeNotificationTime } from '@/lib/notification-api';
import { logout } from '@/lib/auth';
import { getNotifications } from '@/lib/notification-api';
import type { AppNotification } from '@/lib/notification-types';
import { useAuthUser } from './auth-boundary';
import { ThemeSelector } from './theme-selector';

export function Header() {
  const pathname = usePathname();
  const currentPath = pathname && pathname !== '/' ? pathname.replace(/\/+$/, '') : pathname || '/';
  const [unread, setUnread] = useState(0);
  const user = useAuthUser();
  const [profileOpen, setProfileOpen] = useState(false);
  const [logoutConfirmOpen, setLogoutConfirmOpen] = useState(false);
  const [signingOut, setSigningOut] = useState(false);
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const [notifications, setNotifications] = useState<AppNotification[]>([]);
  const [notificationsLoading, setNotificationsLoading] = useState(false);
  const [liveToast, setLiveToast] = useState<AppNotification | null>(null);
  const audioContext = useRef<AudioContext | null>(null);
  const toastTimer = useRef<number | null>(null);
  const notificationMenuRef = useRef<HTMLDivElement>(null);
  const cancelLogoutRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!notificationsOpen) return;
    const closeOnOutsidePointer = (event: PointerEvent) => {
      if (event.target instanceof Node && !notificationMenuRef.current?.contains(event.target)) setNotificationsOpen(false);
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setNotificationsOpen(false);
    };
    document.addEventListener('pointerdown', closeOnOutsidePointer);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('pointerdown', closeOnOutsidePointer);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [notificationsOpen]);

  useEffect(() => {
    if (!logoutConfirmOpen) return;
    cancelLogoutRef.current?.focus();
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !signingOut) setLogoutConfirmOpen(false);
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [logoutConfirmOpen, signingOut]);

  useEffect(() => {
    if (!user?.id) return;

    let active = true;
    let initialized = false;
    let polling = false;
    const knownIds = new Set<string>();
    const handledKey = `maintenance:notification-alerts:${user.id}`;
    let wasHidden = document.visibilityState === 'hidden';

    const unlockSound = () => {
      if (!audioContext.current && 'AudioContext' in window) audioContext.current = new AudioContext();
      if (audioContext.current?.state === 'suspended') void audioContext.current.resume().catch(() => undefined);
    };
    const playSound = () => {
      const context = audioContext.current;
      if (!context || context.state !== 'running') return;
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.value = 880;
      gain.gain.setValueAtTime(0.0001, context.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.07, context.currentTime + 0.015);
      gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.16);
      oscillator.connect(gain);
      gain.connect(context.destination);
      oscillator.start();
      oscillator.stop(context.currentTime + 0.17);
    };
    const browserAlert = async (item: AppNotification, background: boolean) => {
      if (!('Notification' in window) || Notification.permission !== 'granted' || (!background && !window.matchMedia('(display-mode: standalone)').matches)) return;
      const basePath = process.env.NEXT_PUBLIC_BASE_PATH ?? '';
      const route = item.url ?? (item.relatedTicketId ? `/tickets/${item.relatedTicketId}` : '/notifications');
      const url = `${basePath}${route.startsWith('/') ? route : `/${route}`}`;
      const description = item.problem ?? item.solution ?? item.body;
      const body = [item.ticketNumber, item.machine, description].filter(Boolean).join(' — ');
      const options: NotificationOptions = {
        body: body || item.body,
        icon: `${basePath}/pwa-icon-192.png`,
        badge: `${basePath}/pwa-icon-192.png`,
        tag: `maintenance-${item.id}`,
        data: { url, notificationId: item.id },
        ...(background ? {} : { silent: true }),
      };
      try {
        const registration = await navigator.serviceWorker?.getRegistration();
        if (registration?.showNotification) await registration.showNotification(item.title, options);
        else new Notification(item.title, options);
      } catch { /* Browser notification permission or capability may change while the app is open. */ }
    };
    const announce = (item: AppNotification, background: boolean) => {
      let handled: string[] = [];
      try { handled = JSON.parse(localStorage.getItem(handledKey) ?? '[]') as string[]; } catch { handled = []; }
      const id = String(item.id);
      if (handled.includes(id)) return;
      try { localStorage.setItem(handledKey, JSON.stringify([...handled, id].slice(-200))); } catch { /* The in-memory seen set still prevents repeat alerts this session. */ }

      setLiveToast(item);
      if (toastTimer.current) window.clearTimeout(toastTimer.current);
      toastTimer.current = window.setTimeout(() => setLiveToast(null), 6500);
      playSound();
      void browserAlert(item, background);
    };
    const poll = async () => {
      if (!active || polling) return;
      polling = true;
      try {
        const page = await getNotifications();
        if (!active) return;
        setUnread(page.unreadCount);
        setNotifications(page.data);
        const newItems = page.data.filter((item) => !knownIds.has(String(item.id)));
        if (!initialized) {
          page.data.forEach((item) => knownIds.add(String(item.id)));
          initialized = true;
          return;
        }
        for (const item of newItems.reverse()) {
          knownIds.add(String(item.id));
          if (!item.read) announce(item, wasHidden || document.visibilityState === 'hidden');
        }
        if (document.visibilityState === 'visible') wasHidden = false;
      } catch { /* Existing notification center remains available and polling retries on its next interval. */ }
      finally { polling = false; }
    };
    const visibilityChange = () => {
      if (document.visibilityState === 'hidden') wasHidden = true;
      else void poll();
    };
    const serviceWorkerMessage = (event: MessageEvent) => {
      const message = event.data as { type?: string; notificationId?: string; url?: string } | undefined;
      if (message?.type !== 'maintenance:notification-click' || !message.notificationId || !message.url) return;
      const target = new URL(message.url, window.location.origin);
      if (target.origin !== window.location.origin) return;
      void markNotificationRead(message.notificationId)
        .then(() => setUnread((count) => Math.max(0, count - 1)))
        .catch(() => undefined)
        .finally(() => window.location.assign(target.href));
    };
    window.addEventListener('pointerdown', unlockSound, { passive: true });
    window.addEventListener('keydown', unlockSound);
    window.addEventListener('focus', poll);
    document.addEventListener('visibilitychange', visibilityChange);
    navigator.serviceWorker?.addEventListener('message', serviceWorkerMessage);
    void poll();
    const timer = window.setInterval(() => void poll(), 20_000);
    return () => {
      active = false;
      window.clearInterval(timer);
      window.removeEventListener('pointerdown', unlockSound);
      window.removeEventListener('keydown', unlockSound);
      window.removeEventListener('focus', poll);
      document.removeEventListener('visibilitychange', visibilityChange);
      navigator.serviceWorker?.removeEventListener('message', serviceWorkerMessage);
      if (toastTimer.current) window.clearTimeout(toastTimer.current);
      if (audioContext.current) { void audioContext.current.close().catch(() => undefined); audioContext.current = null; }
    };
  }, [user?.id]);

  const initials = user?.name.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase() ?? 'OP';
  async function signOut() {
    if (signingOut) return;
    setSigningOut(true);
    try { await logout(); } finally { window.location.assign('/login'); }
  }
  async function toggleNotifications() {
    const nextOpen = !notificationsOpen;
    setNotificationsOpen(nextOpen);
    if (nextOpen) {
      setNotificationsLoading(true);
      try {
        const page = await getNotifications();
        setNotifications(page.data);
        setUnread(page.unreadCount);
      } catch { setNotifications([]); } finally { setNotificationsLoading(false); }
    }
  }
  async function openNotification(event: MouseEvent<HTMLAnchorElement>, item: AppNotification) {
    event.preventDefault();
    try {
      if (!item.read) {
        await markNotificationRead(item.id);
        setNotifications((current) => current.map((entry) => entry.id === item.id ? { ...entry, read: true } : entry));
        setUnread((count) => Math.max(0, count - 1));
      }
      setNotificationsOpen(false);
      window.location.assign(item.url ?? (item.relatedTicketId ? `/tickets/${item.relatedTicketId}` : '/notifications'));
    } catch { /* Leave the user in place if the read request fails. */ }
  }
  const liveToastTarget = liveToast?.url ?? (liveToast?.relatedTicketId ? `/tickets/${liveToast.relatedTicketId}` : '/notifications');
  return <><header className="topbar"><div className="breadcrumb"><Home aria-hidden="true" /><strong>{currentPath}</strong></div><div className="topbar-actions"><div className="notification-menu" ref={notificationMenuRef}><button className="notification-indicator" type="button" onClick={toggleNotifications} aria-label={`${unread} notifikasi belum dibaca`} aria-expanded={notificationsOpen}><Bell aria-hidden="true" />{unread > 0 && <b>{unread > 99 ? '99+' : unread}</b>}</button>{notificationsOpen && <div className="notification-dropdown"><div className="notification-dropdown-header"><strong>Notifikasi</strong><span>{unread} belum dibaca</span></div>{notificationsLoading ? <p className="notification-empty">Memuat notifikasi...</p> : notifications.length === 0 ? <p className="notification-empty">Tidak ada notifikasi</p> : <div className="notification-dropdown-list">{notifications.slice(0, 5).map((item) => <Link href={item.url ?? (item.relatedTicketId ? `/tickets/${item.relatedTicketId}` : '/notifications')} key={item.id} onClick={(event) => void openNotification(event, item)} className={item.read ? '' : 'notification-item--unread'}><strong>{item.title}</strong>{item.ticketNumber && <span className="notification-ticket-number">{item.ticketNumber}</span>}<span>{item.machine ? `${item.machine}${item.problem ? ` · ${item.problem}` : ''}` : item.body}</span><small>{relativeNotificationTime(item.createdAt)}</small></Link>)}</div>}<Link className="notification-dropdown-footer" href="/notifications" onClick={() => setNotificationsOpen(false)}>Lihat semua notifikasi</Link></div>}</div><ThemeSelector /><div className="profile-menu"><button className="profile profile-trigger" type="button" onClick={() => setProfileOpen((open) => !open)} aria-expanded={profileOpen} aria-label="Buka menu profil"><UserCircle className="profile-icon" aria-hidden="true" /><span className="profile-avatar">{initials}</span><span className="profile-name">{user?.name ?? 'Memuat pengguna'}</span><ChevronDown className="profile-chevron" aria-hidden="true" /></button>{profileOpen && <div className="profile-dropdown"><strong>{user?.name ?? 'Pengguna'}</strong><small>{user?.role ?? 'Sesi'}</small><button type="button" onClick={() => { setProfileOpen(false); setLogoutConfirmOpen(true); }}>Keluar</button></div>}</div></div></header>{liveToast && <div className="live-notification-toast" role="status" aria-live="polite"><span className="live-notification-toast__icon"><Bell aria-hidden="true" /></span><Link className="live-notification-toast__content" href={liveToastTarget} onClick={(event) => void openNotification(event, liveToast)}><strong>{liveToast.title}</strong><span>{liveToast.ticketNumber ? `${liveToast.ticketNumber} · ` : ''}{liveToast.problem ?? liveToast.solution ?? liveToast.body}</span></Link><button type="button" aria-label="Tutup notifikasi" onClick={() => setLiveToast(null)}>×</button></div>}{logoutConfirmOpen && <div className="machine-modal-backdrop logout-confirm-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !signingOut) setLogoutConfirmOpen(false); }}><section className="form-card machine-modal logout-confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="logout-confirm-title" aria-describedby="logout-confirm-description"><span className="logout-confirm-icon"><AlertTriangle aria-hidden="true" /></span><h2 id="logout-confirm-title">Keluar dari MIRA?</h2><p id="logout-confirm-description">Anda akan mengakhiri sesi akun ini. Yakin ingin keluar?</p><div className="machine-modal-actions"><button ref={cancelLogoutRef} type="button" className="secondary-action" disabled={signingOut} onClick={() => setLogoutConfirmOpen(false)}>Batal</button><button type="button" className="danger-button" disabled={signingOut} onClick={() => void signOut()}>{signingOut ? 'Sedang keluar…' : 'Keluar'}</button></div></section></div>}</>;
}
