'use client';

import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { BrowserMultiFormatReader } from '@zxing/browser';
import type { IScannerControls } from '@zxing/browser';
import { Camera, Keyboard, RotateCcw } from 'lucide-react';

export function MachineQrScanner({
  scannerKey,
  loading,
  error,
  onScan,
  onManual,
  onRetry,
}: {
  scannerKey: number;
  loading: boolean;
  error: string;
  onScan: (payload: string) => void;
  onManual: (code: string) => void;
  onRetry: () => void;
}) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const controlsRef = useRef<IScannerControls | null>(null);
  const scanHandlerRef = useRef(onScan);
  const [cameraState, setCameraState] = useState<'starting' | 'ready' | 'error'>('starting');
  const [cameraError, setCameraError] = useState('');
  const [manualOpen, setManualOpen] = useState(false);
  const [manualCode, setManualCode] = useState('');
  const handled = useRef(false);
  scanHandlerRef.current = onScan;

  useEffect(() => { if (error || cameraState === 'error') setManualOpen(true); }, [cameraState, error]);

  useEffect(() => {
    let active = true;
    handled.current = false;
    setCameraState('starting');
    setCameraError('');
    const reader = new BrowserMultiFormatReader();

    reader.decodeFromConstraints(
      { audio: false, video: { facingMode: { ideal: 'environment' } } },
      videoRef.current ?? undefined,
      (result, _decodeError, controls) => {
        controlsRef.current = controls;
        if (!active || !result || handled.current) return;
        handled.current = true;
        controls.stop();
        setCameraState('ready');
        scanHandlerRef.current(result.getText());
      },
    ).then((controls) => {
      controlsRef.current = controls;
      if (active) setCameraState('ready');
      else controls.stop();
    }).catch((reason: unknown) => {
      if (!active) return;
      const name = reason instanceof Error ? reason.name : '';
      setCameraState('error');
      setCameraError(name === 'NotAllowedError' || name === 'SecurityError'
        ? 'Izin kamera tidak tersedia. Masukkan kode mesin secara manual.'
        : name === 'NotFoundError' || name === 'DevicesNotFoundError'
          ? 'Kamera tidak ditemukan. Masukkan kode mesin secara manual.'
          : 'Kamera tidak dapat dibuka. Masukkan kode mesin secara manual.');
    });

    return () => {
      active = false;
      controlsRef.current?.stop();
      controlsRef.current = null;
    };
  }, [scannerKey]);

  function submitManual(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (manualCode.trim()) onManual(manualCode.trim());
  }

  return <div className="machine-qr-scanner">
    <div className="machine-qr-scanner__preview">
      <video ref={videoRef} muted playsInline aria-label="Pratinjau kamera scanner QR" />
      {cameraState === 'starting' && <div className="machine-qr-scanner__overlay"><Camera aria-hidden="true" /><span>Membuka kamera...</span></div>}
      {cameraState === 'ready' && !loading && <div className="machine-qr-scanner__frame" aria-hidden="true"><i /></div>}
      {loading && <div className="machine-qr-scanner__overlay"><span>Memeriksa mesin...</span></div>}
    </div>
    <p className="machine-qr-scanner__instruction">Arahkan kamera ke QR Code mesin.</p>
    {(cameraError || error) && <p className="machine-qr-scanner__error" role="alert">{error || cameraError}</p>}
    {(cameraState === 'error' || error) && <button className="mobile-action-dialog__secondary" type="button" onClick={onRetry}><RotateCcw aria-hidden="true" /> Coba scan lagi</button>}
    {!manualOpen ? <button className="machine-qr-scanner__manual" type="button" onClick={() => setManualOpen(true)}><Keyboard aria-hidden="true" /> Masukkan Kode Mesin Manual</button> : <form className="machine-qr-scanner__manual-form" onSubmit={submitManual}><label htmlFor="manual-machine-qr">Kode Plant / Mesin</label><input id="manual-machine-qr" value={manualCode} onChange={(event) => setManualCode(event.target.value)} placeholder="RX03/MCH-001" autoComplete="off" />{cameraError && <small>{cameraError}</small>}<button className="mobile-action-dialog__primary" type="submit" disabled={!manualCode.trim() || loading}>{loading ? 'Memeriksa...' : 'Cari Mesin'}</button></form>}
  </div>;
}
