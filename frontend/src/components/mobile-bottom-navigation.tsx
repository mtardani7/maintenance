'use client';

import { useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import Link from 'next/link';
import { Bell, ClipboardList, House, Plus, QrCode, UserRound, X } from 'lucide-react';
import { useAuthUser } from './auth-boundary';
import { MachineQrScanner } from './machine-qr-scanner';
import { resolveMachineQr } from '@/lib/maintenance-api';
import { ApiError } from '@/lib/api';
import { manualMachineQrPayload } from '@/lib/machine-qr';

export function MobileBottomNavigation() {
  const pathname = usePathname();
  const router = useRouter();
  const [choiceOpen, setChoiceOpen] = useState(false);
  const [scanOpen, setScanOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const [scanError, setScanError] = useState('');
  const [scanLoading, setScanLoading] = useState(false);
  const [scannerKey, setScannerKey] = useState(0);
  const user = useAuthUser();
  const isOperator = user?.role === 'operator';
  const isMaintenance = user?.role === 'technician';
  const secondaryHref = isOperator ? '/incidents' : isMaintenance ? '/tickets' : '/incidents';
  const secondaryLabel = isOperator ? 'Insiden' : isMaintenance ? 'Tiket' : 'Riwayat';
  const secondaryActive = isMaintenance
    ? pathname.startsWith('/tickets')
    : isOperator
      ? pathname.startsWith('/incidents')
      : pathname.startsWith('/incidents') || pathname.startsWith('/tickets');

  function openManualReport() {
    setChoiceOpen(false);
    setScanOpen(false);
    if (pathname === '/incidents') {
      window.dispatchEvent(new Event('maintenance:open-incident-report'));
      return;
    }
    window.sessionStorage.setItem('maintenance:open-incident-report', '1');
    router.push('/incidents');
  }

  async function submitQrPayload(payload: string) {
    setScanError('');
    setScanLoading(true);
    try {
      const machine = await resolveMachineQr(payload);
      const prefill = { ...machine, payload };
      setChoiceOpen(false);
      setScanOpen(false);
      setScanLoading(false);
      if (pathname === '/incidents') {
        window.dispatchEvent(new CustomEvent('maintenance:open-incident-report', { detail: prefill }));
      } else {
        window.sessionStorage.setItem('maintenance:prefill-incident-qr', JSON.stringify(prefill));
        window.sessionStorage.setItem('maintenance:open-incident-report', '1');
        router.push('/incidents');
      }
    } catch (reason) {
      const apiError = reason instanceof ApiError ? reason : null;
      setScanError(apiError?.status === 404
        ? 'Mesin tidak ditemukan.'
        : apiError?.status === 422
          ? apiError.payload?.message === 'Mesin sedang tidak aktif dan tidak dapat digunakan untuk laporan.'
            ? apiError.payload.message
            : 'QR Mesin tidak valid.'
          : 'Gagal mengambil data mesin. Periksa koneksi dan coba lagi.');
      setScanLoading(false);
    }
  }

  function submitManualCode(code: string) {
    const payload = manualMachineQrPayload(code);
    if (!payload) {
      setScanError('Format kode tidak valid. Masukkan Kode Plant/Kode Mesin, contoh RX03/MCH-001.');
      return;
    }
    void submitQrPayload(payload);
  }

  function openAccount() { setAccountOpen(true); }

  return <>
    <nav className="mobile-bottom-nav" aria-label="Navigasi mobile">
      <Link className={`mobile-bottom-nav__item ${pathname === '/dashboard' ? 'is-active' : ''}`} href="/dashboard"><House aria-hidden="true" /><span>Beranda</span></Link>
      <Link className={`mobile-bottom-nav__item ${secondaryActive ? 'is-active' : ''}`} href={secondaryHref}><ClipboardList aria-hidden="true" /><span>{secondaryLabel}</span></Link>
      <button className="mobile-bottom-nav__item mobile-bottom-nav__create" type="button" onClick={() => { setChoiceOpen(true); setScanOpen(false); }} aria-label="Buat Laporan"><span className="mobile-bottom-nav__plus"><Plus aria-hidden="true" /></span><span>Lapor</span></button>
      <Link className={`mobile-bottom-nav__item ${pathname.startsWith('/notifications') ? 'is-active' : ''}`} href="/notifications"><Bell aria-hidden="true" /><span>Notifikasi</span></Link>
      <button className={`mobile-bottom-nav__item ${accountOpen ? 'is-active' : ''}`} type="button" onClick={() => void openAccount()}><UserRound aria-hidden="true" /><span>Akun</span></button>
    </nav>

    {choiceOpen && <div className="mobile-action-backdrop" role="presentation" onClick={() => setChoiceOpen(false)}><section className="mobile-action-dialog" role="dialog" aria-modal="true" aria-labelledby="mobile-action-title" onClick={(event) => event.stopPropagation()}>
      <div className="mobile-action-dialog__heading"><div><h2 id="mobile-action-title">{scanOpen ? 'Scan QR Mesin' : 'Buat Laporan'}</h2><p>{scanOpen ? 'Arahkan kamera ke QR yang terpasang pada mesin.' : 'Bagaimana Anda ingin membuat laporan?'}</p></div><button type="button" className="mobile-action-dialog__close" onClick={() => { setChoiceOpen(false); setScanOpen(false); setScanError(''); }} aria-label="Tutup"><X aria-hidden="true" /></button></div>
      {scanOpen ? <><MachineQrScanner scannerKey={scannerKey} loading={scanLoading} error={scanError} onScan={submitQrPayload} onManual={submitManualCode} onRetry={() => { setScanError(''); setScannerKey((key) => key + 1); }} /><div className="mobile-action-dialog__actions"><button className="mobile-action-dialog__secondary" type="button" onClick={() => { setScanOpen(false); setScanError(''); }}>Kembali</button></div></> : <div className="mobile-action-options"><button className="mobile-action-option" type="button" onClick={openManualReport}><span className="mobile-action-option__icon"><ClipboardList aria-hidden="true" /></span><span><strong>Isi Manual</strong><small>Laporkan masalah dengan memilih mesin dan mengisi informasi.</small></span></button><button className="mobile-action-option" type="button" onClick={() => { setScanError(''); setScanOpen(true); }}><span className="mobile-action-option__icon"><QrCode aria-hidden="true" /></span><span><strong>Scan QR Mesin</strong><small>Gunakan QR yang terpasang pada mesin.</small></span></button><button className="mobile-action-dialog__secondary" type="button" onClick={() => setChoiceOpen(false)}>Batal</button></div>}
    </section></div>}

    {accountOpen && <div className="mobile-action-backdrop" role="presentation" onClick={() => setAccountOpen(false)}><section className="mobile-action-dialog mobile-account-dialog" role="dialog" aria-modal="true" aria-labelledby="mobile-account-title" onClick={(event) => event.stopPropagation()}><div className="mobile-action-dialog__heading"><div><h2 id="mobile-account-title">Akun</h2><p>Informasi akun yang sedang digunakan.</p></div><button type="button" className="mobile-action-dialog__close" onClick={() => setAccountOpen(false)} aria-label="Tutup"><X aria-hidden="true" /></button></div><div className="mobile-account-info"><span className="mobile-account-info__avatar">{user?.name?.slice(0, 2).toUpperCase() ?? <UserRound aria-hidden="true" />}</span><div><strong>{user?.name ?? 'Pengguna'}</strong><small>{user?.email ?? 'Akun Maintenance'}</small><small>{user?.role ?? 'Pengguna'}</small></div></div><button className="mobile-action-dialog__secondary" type="button" onClick={() => setAccountOpen(false)}>OK</button></section></div>}
  </>;
}
