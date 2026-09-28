'use client';

import { useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import Link from 'next/link';
import { Bell, ClipboardList, House, Plus, QrCode, UserRound, X } from 'lucide-react';
import { getCurrentUser } from '@/lib/auth';
import type { User } from '@/lib/types';

export function MobileBottomNavigation() {
  const pathname = usePathname();
  const router = useRouter();
  const [choiceOpen, setChoiceOpen] = useState(false);
  const [scanOpen, setScanOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const [user, setUser] = useState<User | null>(null);
  const [userLoaded, setUserLoaded] = useState(false);

  function openManualReport() {
    setChoiceOpen(false);
    if (pathname === '/incidents') {
      window.dispatchEvent(new Event('maintenance:open-incident-report'));
      return;
    }
    window.sessionStorage.setItem('maintenance:open-incident-report', '1');
    router.push('/incidents');
  }

  async function openAccount() {
    setAccountOpen(true);
    if (!userLoaded) {
      const result = await getCurrentUser();
      if (result.status === 'authenticated') setUser(result.user);
      setUserLoaded(true);
    }
  }

  return <>
    <nav className="mobile-bottom-nav" aria-label="Navigasi mobile">
      <Link className={`mobile-bottom-nav__item ${pathname === '/dashboard' ? 'is-active' : ''}`} href="/dashboard"><House aria-hidden="true" /><span>Beranda</span></Link>
      <Link className={`mobile-bottom-nav__item ${pathname.startsWith('/incidents') || pathname.startsWith('/tickets') ? 'is-active' : ''}`} href="/incidents"><ClipboardList aria-hidden="true" /><span>Riwayat</span></Link>
      <button className="mobile-bottom-nav__item mobile-bottom-nav__create" type="button" onClick={() => { setChoiceOpen(true); setScanOpen(false); }} aria-label="Buat Laporan"><span className="mobile-bottom-nav__plus"><Plus aria-hidden="true" /></span><span>Lapor</span></button>
      <Link className={`mobile-bottom-nav__item ${pathname.startsWith('/notifications') ? 'is-active' : ''}`} href="/notifications"><Bell aria-hidden="true" /><span>Notifikasi</span></Link>
      <button className={`mobile-bottom-nav__item ${accountOpen ? 'is-active' : ''}`} type="button" onClick={() => void openAccount()}><UserRound aria-hidden="true" /><span>Akun</span></button>
    </nav>

    {choiceOpen && <div className="mobile-action-backdrop" role="presentation" onClick={() => setChoiceOpen(false)}><section className="mobile-action-dialog" role="dialog" aria-modal="true" aria-labelledby="mobile-action-title" onClick={(event) => event.stopPropagation()}>
      <div className="mobile-action-dialog__heading"><div><h2 id="mobile-action-title">{scanOpen ? 'Scan QR Mesin' : 'Buat Laporan'}</h2><p>{scanOpen ? 'Fitur scan QR mesin akan digunakan pada pengembangan berikutnya.' : 'Bagaimana Anda ingin membuat laporan?'}</p></div><button type="button" className="mobile-action-dialog__close" onClick={() => { setChoiceOpen(false); setScanOpen(false); }} aria-label="Tutup"><X aria-hidden="true" /></button></div>
      {scanOpen ? <div className="mobile-action-dialog__actions"><button className="mobile-action-dialog__secondary" type="button" onClick={() => setScanOpen(false)}>Kembali</button></div> : <div className="mobile-action-options"><button className="mobile-action-option" type="button" onClick={openManualReport}><span className="mobile-action-option__icon"><ClipboardList aria-hidden="true" /></span><span><strong>Isi Manual</strong><small>Laporkan masalah dengan memilih mesin dan mengisi informasi.</small></span></button><button className="mobile-action-option" type="button" onClick={() => setScanOpen(true)}><span className="mobile-action-option__icon"><QrCode aria-hidden="true" /></span><span><strong>Scan QR Mesin</strong><small>Gunakan QR yang terpasang pada mesin.</small></span></button><button className="mobile-action-dialog__secondary" type="button" onClick={() => setChoiceOpen(false)}>Batal</button></div>}
    </section></div>}

    {accountOpen && <div className="mobile-action-backdrop" role="presentation" onClick={() => setAccountOpen(false)}><section className="mobile-action-dialog mobile-account-dialog" role="dialog" aria-modal="true" aria-labelledby="mobile-account-title" onClick={(event) => event.stopPropagation()}><div className="mobile-action-dialog__heading"><div><h2 id="mobile-account-title">Akun</h2><p>Informasi akun yang sedang digunakan.</p></div><button type="button" className="mobile-action-dialog__close" onClick={() => setAccountOpen(false)} aria-label="Tutup"><X aria-hidden="true" /></button></div><div className="mobile-account-info"><span className="mobile-account-info__avatar">{user?.name?.slice(0, 2).toUpperCase() ?? <UserRound aria-hidden="true" />}</span><div><strong>{user?.name ?? 'Pengguna'}</strong><small>{user?.email ?? 'Akun Maintenance'}</small><small>{user?.role ?? 'Pengguna'}</small></div></div><button className="mobile-action-dialog__secondary" type="button" onClick={() => setAccountOpen(false)}>OK</button></section></div>}
  </>;
}
