'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { addSparePart, apiMessage, getMaintenanceUsers, getTicket, performTicketAction, removeSparePart, updateBreakdownAnalysis, updateVerificationChecklist } from '@/lib/ticket-api';
import { getCurrentUser } from '@/lib/auth';
import type { BreakdownAnalysisInput, MaintenanceUser, SparePart, Ticket, TicketActionInput, VerificationChecklist, VerificationKey, VerificationValue } from '@/lib/ticket-types';
import type { Role } from '@/lib/types';
import { DetailSkeleton, ErrorState } from './ui';

function label(value: string) {
  return value.replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatDateTime(value?: string) {
  if (!value) return '--';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'long',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
  }).format(date);
}

const verificationItems: Array<{ key: VerificationKey; label: string }> = [
  { key: 'machine_cleanliness', label: 'Kebersihan mesin' },
  { key: 'water', label: 'Air' },
  { key: 'grease', label: 'Pelumas' },
  { key: 'gram', label: 'Gram' },
  { key: 'machine_function', label: 'Fungsi mesin' },
  { key: 'machine_safety', label: 'Keselamatan mesin' },
  { key: 'tool', label: 'Peralatan' },
];

const statusLabels: Record<string, string> = { OPEN: 'Terbuka', CLOSED: 'Ditutup' };
const problemLabels: Record<string, string> = { 'Machine stopped': 'Mesin berhenti', 'Abnormal sound': 'Suara tidak normal', 'Sensor problem': 'Masalah sensor', 'Quality problem': 'Masalah mutu', Other: 'Lainnya' };
const sourceLabels: Record<string, string> = { OPERATOR: 'Operator', QA: 'Mutu', MANUAL: 'Manual' };

export function TicketDetailView({ ticketId }: { ticketId: string }) {
  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [role, setRole] = useState<Role | null>(null);
  const [maintenanceUsers, setMaintenanceUsers] = useState<MaintenanceUser[]>([]);
  const [error, setError] = useState('');
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);
  const [reason, setReason] = useState('');
  const [actionTaken, setActionTaken] = useState('');
  const [executorId, setExecutorId] = useState('');
  const [durationHours, setDurationHours] = useState('');
  const [solution, setSolution] = useState('');
  const [showSparePartForm, setShowSparePartForm] = useState(false);
  const [sparePartName, setSparePartName] = useState('');
  const [materialCode, setMaterialCode] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [remark, setRemark] = useState('');
  const [sparePartBusy, setSparePartBusy] = useState(false);
  const [rootCauseAnalysis, setRootCauseAnalysis] = useState('');
  const [correctiveActionPlan, setCorrectiveActionPlan] = useState('');
  const [targetAt, setTargetAt] = useState('');
  const [actionById, setActionById] = useState('');
  const [analysisBusy, setAnalysisBusy] = useState(false);
  const [verification, setVerification] = useState<VerificationChecklist>({});
  const [verificationBusy, setVerificationBusy] = useState(false);
  const [successMessage, setSuccessMessage] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => {
    setTicket(null);
    setError('');
    getTicket(ticketId).then((result) => {
      setTicket(result);
      setReason(result.reason ?? '');
      setActionTaken(result.actionTaken ?? '');
      setExecutorId(result.executor?.id ? String(result.executor.id) : '');
      setDurationHours(result.durationHours ? String(result.durationHours) : '');
      setSolution(result.solution ?? '');
      setRootCauseAnalysis(result.rootCauseAnalysis ?? '');
      setCorrectiveActionPlan(result.correctiveActionPlan ?? '');
      setTargetAt(result.targetAt ? result.targetAt.replace(' ', 'T').slice(0, 16) : '');
      setActionById(result.actionBy?.id ? String(result.actionBy.id) : '');
      setVerification(result.verificationChecklist ?? {});
    }).catch((requestError) => setError(apiMessage(requestError)));
    getCurrentUser().then((result) => {
      if (result.status !== 'authenticated') return;
      setRole(result.user.role ?? null);
      if (result.user.role === 'technician') {
        getMaintenanceUsers().then(setMaintenanceUsers).catch(() => setMaintenanceUsers([]));
      }
    });
  }, [ticketId, retryKey]);

  if (error && !ticket) return <ErrorState title="Tiket tidak tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => { setError(''); setRetryKey((value) => value + 1); }} />;
  if (!ticket) return <DetailSkeleton sections={["Informasi tiket", "Hasil pekerjaan", "Suku cadang", "Analisis gangguan", "Verifikasi", "Riwayat"]} />;

  const canManage = role === 'technician';

  async function closeTicket() {
    if (!reason.trim() || !actionTaken.trim() || !executorId || !durationHours || Number(durationHours) <= 0 || !solution.trim()) {
      setActionError('Lengkapi penyebab, tindakan, pelaksana, durasi, dan hasil pekerjaan.');
      return;
    }
    setActionError('');
    setBusy(true);
    try {
      const input: TicketActionInput = { reason: reason.trim(), actionTaken: actionTaken.trim(), executorId, durationHours: Number(durationHours), solution: solution.trim() };
      setTicket(await performTicketAction(ticketId, 'close', input));
      setSuccessMessage('Tiket berhasil ditutup dan tersimpan di riwayat.');
    } catch (requestError) {
      setActionError(apiMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function saveSparePart() {
    if (!sparePartName.trim() || !materialCode.trim() || !quantity || Number(quantity) < 1) {
      setActionError('Lengkapi nama spare part, kode material, dan jumlah.');
      return;
    }
    setActionError('');
    setSparePartBusy(true);
    try {
      const part = await addSparePart(ticketId, { name: sparePartName.trim(), materialCode: materialCode.trim(), quantity: Number(quantity), remark: remark.trim() });
      setTicket((current) => current ? { ...current, spareParts: [...current.spareParts, part] } : current);
      setSuccessMessage('Suku cadang berhasil ditambahkan.');
      setSparePartName('');
      setMaterialCode('');
      setQuantity('1');
      setRemark('');
      setShowSparePartForm(false);
    } catch (requestError) {
      setActionError(apiMessage(requestError));
    } finally {
      setSparePartBusy(false);
    }
  }

  async function deleteSparePart(part: SparePart) {
    setActionError('');
    setSparePartBusy(true);
    try {
      await removeSparePart(ticketId, part.id);
      setTicket((current) => current ? { ...current, spareParts: current.spareParts.filter((item) => item.id !== part.id) } : current);
      setSuccessMessage('Suku cadang berhasil dihapus.');
    } catch (requestError) {
      setActionError(apiMessage(requestError));
    } finally {
      setSparePartBusy(false);
    }
  }

  async function saveAnalysis() {
    setActionError('');
    setAnalysisBusy(true);
    try {
      const input: BreakdownAnalysisInput = { rootCauseAnalysis: rootCauseAnalysis.trim() || undefined, correctiveActionPlan: correctiveActionPlan.trim() || undefined, targetAt: targetAt || undefined, actionById: actionById || undefined };
      setTicket(await updateBreakdownAnalysis(ticketId, input));
      setSuccessMessage('Analisis kerusakan berhasil disimpan.');
    } catch (requestError) {
      setActionError(apiMessage(requestError));
    } finally {
      setAnalysisBusy(false);
    }
  }

  async function saveVerification() {
    setActionError('');
    setVerificationBusy(true);
    try {
      setTicket(await updateVerificationChecklist(ticketId, verification));
      setSuccessMessage('Pemeriksaan berhasil disimpan.');
    } catch (requestError) {
      setActionError(apiMessage(requestError));
    } finally {
      setVerificationBusy(false);
    }
  }

  return <div className="ticket-detail-layout">
    <main>
      <div className="ticket-detail-hero"><div><p className="eyebrow">{ticket.number}</p><h1>{problemLabels[ticket.problemType] ?? ticket.problemType}</h1><p>{ticket.plant} / {ticket.machine.code} / {ticket.machine.name}</p></div><b className={`ticket-status ticket-status--${ticket.status.toLowerCase()}`}>{statusLabels[ticket.status] ?? label(ticket.status)}</b></div>
      <section className="ticket-info-card ticket-summary-card">
        <h2>Informasi ticket</h2>
        <dl className="ticket-facts"><div><dt>Nomor tiket</dt><dd>{ticket.number}</dd></div><div><dt>Plant</dt><dd>{ticket.plant}</dd></div><div><dt>Mesin</dt><dd>{ticket.machine.code} / {ticket.machine.name}</dd></div><div><dt>Masalah</dt><dd>{problemLabels[ticket.problemType] ?? ticket.problemType}</dd></div><div><dt>Prioritas</dt><dd>{({ CRITICAL: 'Kritis', HIGH: 'Tinggi', MEDIUM: 'Sedang', LOW: 'Rendah' } as Record<string, string>)[ticket.priority] ?? ticket.priority}</dd></div><div><dt>Pelapor</dt><dd>{ticket.reporter?.name ?? 'Operator'}</dd></div><div><dt>Sumber laporan</dt><dd>{sourceLabels[ticket.sourceType ?? ''] ?? '—'}</dd></div><div><dt>Status</dt><dd><span className={`ticket-status ticket-status--${ticket.status.toLowerCase()}`}>{statusLabels[ticket.status] ?? label(ticket.status)}</span></dd></div></dl>
        <div className="ticket-description"><dt>Deskripsi masalah</dt><p>{ticket.description}</p></div>
      </section>
      <section className="ticket-info-card breakdown-card ticket-breakdown-section">
        <div className="card-heading"><div><h2>Analisis Kerusakan</h2><p>Analisis lanjutan (opsional).</p></div></div>
        {ticket.status === 'OPEN' && canManage ? <div className="breakdown-form"><label className="form-field">Analisis akar masalah<textarea rows={3} value={rootCauseAnalysis} onChange={(event) => setRootCauseAnalysis(event.target.value)} placeholder="Jelaskan akar masalah" /></label><label className="form-field">Rencana tindakan perbaikan<textarea rows={3} value={correctiveActionPlan} onChange={(event) => setCorrectiveActionPlan(event.target.value)} placeholder="Jelaskan rencana perbaikan" /></label><div className="work-detail-grid"><label className="form-field">Target penyelesaian<input type="datetime-local" value={targetAt} onChange={(event) => setTargetAt(event.target.value)} /></label><label className="form-field">Penanggung jawab<select value={actionById} onChange={(event) => setActionById(event.target.value)}><option value="">Pilih petugas</option>{maintenanceUsers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}</select></label></div><button className="secondary-action" type="button" onClick={() => void saveAnalysis()} disabled={analysisBusy}>{analysisBusy ? 'Menyimpan...' : 'Simpan analisis'}</button></div> : <dl className="ticket-facts work-detail-history"><div className="work-detail-wide"><dt>Analisis akar masalah</dt><dd>{ticket.rootCauseAnalysis || 'Belum diisi'}</dd></div><div className="work-detail-wide"><dt>Rencana tindakan perbaikan</dt><dd>{ticket.correctiveActionPlan || 'Belum diisi'}</dd></div><div><dt>Target penyelesaian</dt><dd>{ticket.targetAt || '—'}</dd></div><div><dt>Penanggung jawab</dt><dd>{ticket.actionBy?.name || '—'}</dd></div></dl>}
      </section>
      <section className="ticket-info-card spare-parts-card ticket-spare-section">
        <div className="card-heading"><div><h2>Suku Cadang</h2><p>Komponen yang digunakan pada pekerjaan ini.</p></div>{ticket.status === 'OPEN' && canManage && <button className="secondary-action" type="button" onClick={() => setShowSparePartForm((current) => !current)} disabled={sparePartBusy}>{showSparePartForm ? 'Batal' : 'Tambah suku cadang'}</button>}</div>
        {ticket.spareParts.length === 0 ? <p className="spare-parts-empty">Belum ada suku cadang yang dicatat.</p> : <div className="spare-parts-list">{ticket.spareParts.map((part) => <div className="spare-part-row" key={part.id}><div><strong>{part.name}</strong><small>{part.materialCode}{part.remark ? ` · ${part.remark}` : ''}</small></div><span>{part.quantity}</span>{ticket.status === 'OPEN' && canManage && <button className="icon-button spare-part-remove" type="button" onClick={() => void deleteSparePart(part)} disabled={sparePartBusy} aria-label={`Hapus ${part.name}`}>×</button>}</div>)}</div>}
        {showSparePartForm && ticket.status === 'OPEN' && canManage && <div className="spare-part-form"><label className="form-field">Nama suku cadang<input value={sparePartName} onChange={(event) => setSparePartName(event.target.value)} placeholder="Contoh: Bearing" /></label><div className="work-detail-grid"><label className="form-field">Kode material<input value={materialCode} onChange={(event) => setMaterialCode(event.target.value)} placeholder="MAT-001" /></label><label className="form-field">Jumlah<input type="number" min="1" step="1" value={quantity} onChange={(event) => setQuantity(event.target.value)} /></label></div><label className="form-field">Keterangan (opsional)<textarea rows={2} value={remark} onChange={(event) => setRemark(event.target.value)} placeholder="Tambahkan keterangan" /></label><button className="primary-button" type="button" onClick={() => void saveSparePart()} disabled={sparePartBusy}>{sparePartBusy ? 'Menyimpan...' : 'Simpan suku cadang'}</button></div>}
      </section>
      <section className="ticket-info-card work-detail-card ticket-work-section">
        <div className="card-heading"><div><h2>Hasil Pekerjaan</h2><p>Detail pekerjaan maintenance pada ticket ini.</p></div></div>
        {ticket.status === 'OPEN' && canManage ? <div className="work-detail-form">
          <label className="form-field">Penyebab<textarea rows={3} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Jelaskan penyebab masalah" /></label>
          <label className="form-field">Tindakan perbaikan<textarea rows={3} value={actionTaken} onChange={(event) => setActionTaken(event.target.value)} placeholder="Jelaskan tindakan yang dilakukan" /></label>
          <div className="work-detail-grid"><label className="form-field">Pelaksana<select value={executorId} onChange={(event) => setExecutorId(event.target.value)}><option value="">Pilih petugas</option>{maintenanceUsers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}</select></label><label className="form-field">Durasi pekerjaan (jam)<input type="number" min="0.25" step="0.25" value={durationHours} onChange={(event) => setDurationHours(event.target.value)} placeholder="Contoh: 1,5" /></label></div>
          <label className="form-field">Hasil pekerjaan<textarea rows={4} value={solution} onChange={(event) => setSolution(event.target.value)} placeholder="Jelaskan hasil akhir pekerjaan" /></label>
          <button className="primary-button" disabled={busy} onClick={() => void closeTicket()}>{busy ? 'Menyimpan...' : 'Tutup Tiket'}</button>
        </div> : <dl className="ticket-facts work-detail-history"><div><dt>Cause / Penyebab</dt><dd>{ticket.reason || '--'}</dd></div><div><dt>Action / Tindakan</dt><dd>{ticket.actionTaken || '--'}</dd></div><div><dt>Executor / Pelaksana</dt><dd>{ticket.executor?.name || '--'}</dd></div><div><dt>Duration / Durasi</dt><dd>{ticket.durationHours ? `${ticket.durationHours} jam` : '--'}</dd></div><div className="work-detail-wide"><dt>Solution / Hasil pekerjaan</dt><dd>{ticket.solution || '--'}</dd></div></dl>}
        {actionError && <ErrorState title="Tiket belum ditutup" description={actionError} />}
        {ticket.status === 'CLOSED' && <p className="ticket-readonly-note">Detail pekerjaan tersimpan di riwayat dan tidak dapat diubah.</p>}
      </section>
      <section className="ticket-info-card verification-card">
        <div className="card-heading"><div><h2>Pemeriksaan Peralatan &amp; Kebersihan</h2><p>Pemeriksaan sebelum tiket ditutup (opsional).</p></div></div>
        {ticket.status === 'CLOSED' || !canManage ? <div className="verification-history-list">{verificationItems.map((item) => <div className="verification-row" key={item.key}><strong>{item.label}</strong><span>{verification[item.key] === 'OK' ? 'Baik' : verification[item.key] === 'NOK' ? 'Perlu tindak lanjut' : verification[item.key] === 'N/A' ? 'Tidak berlaku' : 'Belum diperiksa'}</span></div>)}</div> : <div className="verification-list">{verificationItems.map((item) => <div className="verification-row" key={item.key}><strong>{item.label}</strong><div className="verification-options">{(['OK', 'NOK', 'N/A'] as VerificationValue[]).map((value) => <label className={`verification-option verification-option--${value.toLowerCase().replace('/', '-')}${verification[item.key] === value ? ' verification-option--selected' : ''}`} key={value}><input type="radio" name={`verification-${item.key}`} value={value} checked={verification[item.key] === value} onChange={() => setVerification((current) => ({ ...current, [item.key]: value }))} disabled={verificationBusy} /><span>{value === 'OK' ? 'Baik' : value === 'NOK' ? 'Perlu tindak lanjut' : 'Tidak berlaku'}</span></label>)}</div></div>)}</div>}
        {ticket.status === 'OPEN' && canManage && <button className="secondary-action verification-save" type="button" onClick={() => void saveVerification()} disabled={verificationBusy}>{verificationBusy ? 'Menyimpan...' : 'Simpan pemeriksaan'}</button>}
      </section>
      {ticket.status === 'CLOSED' && <section className="ticket-info-card closed-history-card"><div className="card-heading"><div><h2>Riwayat penutupan</h2><p>Tiket ini sudah ditutup. Informasi pekerjaan tersimpan dan tidak dapat diubah.</p></div></div><dl className="ticket-facts"><div><dt>Waktu ditutup</dt><dd>{formatDateTime(ticket.closedAt)}</dd></div><div><dt>Ditutup oleh</dt><dd>{ticket.closedBy?.name || '—'}</dd></div></dl></section>}
    </main>
    <aside className="ticket-actions"><Link className="back-link" href="/tickets">Kembali ke daftar tiket</Link><section className="action-card"><p className="eyebrow">Pemeliharaan</p><p className="action-note">{ticket.status === 'OPEN' ? 'Lengkapi hasil pekerjaan untuk menutup tiket.' : 'Tiket sudah ditutup dan tersimpan di riwayat.'}</p></section></aside>
    {successMessage && <div className="machine-modal-backdrop" role="presentation"><section className="machine-modal machine-success-modal" role="alertdialog" aria-modal="true" aria-labelledby="ticket-action-success-title"><span className="machine-success-icon">✓</span><h2 id="ticket-action-success-title">Berhasil</h2><p>{successMessage}</p><div className="machine-modal-actions"><button className="primary-button" type="button" onClick={() => setSuccessMessage('')}>OK</button></div></section></div>}
  </div>;
}
