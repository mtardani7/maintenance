'use client';

import { useEffect, useState } from 'react';
import { ErrorState, Skeleton } from './ui';

type BrowserNotificationState = 'checking' | 'unsupported' | 'default' | 'granted' | 'denied' | 'error';

export function PushSettings() {
  const [state, setState] = useState<BrowserNotificationState>('checking');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!('Notification' in window) || !('serviceWorker' in navigator)) {
      setState('unsupported');
      return;
    }
    setState(Notification.permission);
  }, []);

  async function enable() {
    if (!('Notification' in window)) return;
    try {
      setError('');
      const permission = await Notification.requestPermission();
      setState(permission);
    } catch {
      setState('error');
      setError('Izin notifikasi tidak dapat diminta. Coba lagi melalui pengaturan browser.');
    }
  }

  if (state === 'checking') return <div className="push-card"><div><Skeleton className="push-skeleton-label" /><Skeleton className="push-skeleton-description" /></div><Skeleton className="push-skeleton-action" /></div>;
  if (state === 'unsupported') return <div className="push-card"><div><span className="push-card__label">Notifikasi browser</span><span className="push-card__muted">Browser ini belum mendukung notifikasi sistem. Notifikasi dalam aplikasi tetap tersedia.</span></div></div>;
  if (state === 'denied') return <div className="push-card"><div><span className="push-card__label">Notifikasi browser</span><span className="push-card__muted">Izin diblokir. Notifikasi dalam aplikasi tetap tersedia; ubah izin melalui pengaturan browser jika ingin mengaktifkannya.</span></div></div>;
  if (state === 'error') return <div className="push-card"><div><span className="push-card__label">Notifikasi browser</span><span className="push-card__muted">{error}</span></div><ErrorState title="Notifikasi belum aktif" description="Notifikasi dalam aplikasi tetap berfungsi." onRetry={() => void enable()} /></div>;

  return <div className="push-card"><div><span className="push-card__label">Notifikasi browser</span><span className="push-card__muted">Dapatkan alert saat MIRA berjalan di tab atau jendela PWA. Alert saat aplikasi tertutup belum tersedia.</span></div>{state === 'granted' ? <strong className="push-enabled">Aktif</strong> : <button className="secondary-action" type="button" onClick={() => void enable()}>Aktifkan notifikasi</button>}</div>;
}
