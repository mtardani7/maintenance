'use client';

import Link from 'next/link';
import { ArrowLeft, ClipboardCheck, Clock3, Plus, RotateCcw, UserRound, Wrench } from 'lucide-react';
import { useEffect, useState } from 'react';
import { addSparePart, apiMessage, correctTicketDowntime, getMaintenanceUsers, getTicket, performTicketAction, removeSparePart, reopenTicket, updateBreakdownAnalysis, updateVerificationChecklist } from '@/lib/ticket-api';
import type { BreakdownAnalysisInput, MaintenanceUser, SparePart, Ticket, TicketActionInput, VerificationChecklist, VerificationKey, VerificationValue } from '@/lib/ticket-types';
import { DetailSkeleton, ErrorState } from './ui';
import { TicketAttachments } from './ticket-attachments';
import { useAuthUser } from './auth-boundary';

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

function localDateTimeInput(value?: string) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date);
  const part = (type: string) => parts.find((item) => item.type === type)?.value ?? '';
  return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}

function inferredDowntimeStart(ticket: Ticket) {
  if (!ticket.closed_at || !ticket.durationHours) return '';
  const end = new Date(ticket.closed_at);
  if (Number.isNaN(end.getTime())) return '';
  end.setTime(end.getTime() - ticket.durationHours * 60 * 60 * 1000);
  return end.toISOString();
}

function currentJakartaDateTimeInput() {
  return localDateTimeInput(new Date().toISOString());
}

function previewDowntime(start: string, end: string) {
  if (!start || !end) return '';
  const startDate = new Date(`${start}:00+07:00`);
  const endDate = new Date(`${end}:00+07:00`);
  const minutes = Math.round((endDate.getTime() - startDate.getTime()) / 60000);
  if (!Number.isFinite(minutes) || minutes <= 0) return 'Waktu selesai harus setelah waktu mulai.';
  const hours = Math.floor(minutes / 60);
  const remainder = minutes % 60;
  return `Durasi ${[hours ? `${hours} jam` : '', remainder ? `${remainder} menit` : ''].filter(Boolean).join(' ')}`;
}

function ticketDurationLabel(ticket: Ticket) {
  const hasStart = Boolean(ticket.actual_downtime_started_at);
  const hasEnd = Boolean(ticket.actual_downtime_ended_at);
  if (hasStart !== hasEnd) return 'Durasi belum lengkap';
  if (hasStart && hasEnd && !ticket.durationHours) return 'Durasi belum lengkap';
  if (!ticket.durationHours) return 'Belum dicatat';
  const minutes = Math.round(ticket.durationHours * 60);
  const hours = Math.floor(minutes / 60);
  const remainder = minutes % 60;
  return [hours ? `${hours} jam` : '', remainder ? `${remainder} menit` : ''].filter(Boolean).join(' ');
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

const problemLabels: Record<string, string> = { 'Machine stopped': 'Mesin berhenti', 'Abnormal sound': 'Suara tidak normal', 'Sensor problem': 'Masalah sensor', 'Quality problem': 'Masalah mutu', Other: 'Lainnya' };
const sourceLabels: Record<string, string> = { OPERATOR: 'Operator', QA: 'Mutu', MANUAL: 'Manual' };
const ticketStatusLabel = (status: Ticket['status']) => status === 'CLOSED' ? 'Selesai' : status === 'OPEN' ? 'Diproses Maintenance' : status;

export function TicketDetailView({ ticketId }: { ticketId: string }) {
  const currentUser = useAuthUser();
  const role = currentUser?.role ?? null;
  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [maintenanceUsers, setMaintenanceUsers] = useState<MaintenanceUser[]>([]);
  const [error, setError] = useState('');
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);
  const [reason, setReason] = useState('');
  const [actionTaken, setActionTaken] = useState('');
  const [workDowntimeStart, setWorkDowntimeStart] = useState('');
  const [workDowntimeEnd, setWorkDowntimeEnd] = useState('');
  const [workDowntimeEndTouched, setWorkDowntimeEndTouched] = useState(false);
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
  const [showCloseConfirmation, setShowCloseConfirmation] = useState(false);
  const [downtimeStart, setDowntimeStart] = useState('');
  const [downtimeEnd, setDowntimeEnd] = useState('');
  const [downtimeReason, setDowntimeReason] = useState('');
  const [downtimeBusy, setDowntimeBusy] = useState(false);
  const [showReopenConfirmation, setShowReopenConfirmation] = useState(false);
  const [reopenReason, setReopenReason] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => {
    setTicket(null);
    setError('');
    getTicket(ticketId).then((result) => {
      setTicket(result);
      setReason(result.reason ?? '');
      setActionTaken(result.actionTaken ?? '');
      setWorkDowntimeStart(localDateTimeInput(result.actual_downtime_started_at));
      setWorkDowntimeEnd(localDateTimeInput(result.actual_downtime_ended_at) || currentJakartaDateTimeInput());
      setWorkDowntimeEndTouched(false);
      setSolution(result.solution ?? '');
      setRootCauseAnalysis(result.rootCauseAnalysis ?? '');
      setCorrectiveActionPlan(result.correctiveActionPlan ?? '');
      setTargetAt(result.targetAt ? result.targetAt.replace(' ', 'T').slice(0, 16) : '');
      setActionById(result.actionBy?.id ? String(result.actionBy.id) : '');
      setVerification(result.verificationChecklist ?? {});
      const hasAnyActualTime = Boolean(result.actual_downtime_started_at || result.actual_downtime_ended_at);
      setDowntimeStart(localDateTimeInput(result.actual_downtime_started_at ?? (hasAnyActualTime ? undefined : inferredDowntimeStart(result))));
      setDowntimeEnd(localDateTimeInput(result.actual_downtime_ended_at ?? (hasAnyActualTime ? undefined : result.closed_at)));
    }).catch((requestError) => setError(apiMessage(requestError)));
    if (role === 'technician') getMaintenanceUsers().then(setMaintenanceUsers).catch(() => setMaintenanceUsers([]));
  }, [ticketId, retryKey, role]);

  if (error && !ticket) return <ErrorState title="Tiket tidak tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => { setError(''); setRetryKey((value) => value + 1); }} />;
  if (!ticket) return <DetailSkeleton sections={["Informasi tiket", "Hasil pekerjaan", "Suku cadang", "Analisis gangguan", "Verifikasi", "Riwayat"]} />;

  const canManage = role === 'technician';
  const isAdmin = role === 'admin';

  function requestCloseTicket() {
    const missing: string[] = [];
    if (!reason.trim()) missing.push('Penyebab');
    if (!actionTaken.trim()) missing.push('Tindakan');
    if (canManage && !currentUser?.id) missing.push('Pelaksana');
    if (!workDowntimeStart) missing.push('Waktu mulai downtime');
    if (!workDowntimeEnd) missing.push('Waktu selesai downtime');
    else if (workDowntimeStart && previewDowntime(workDowntimeStart, workDowntimeEnd).startsWith('Waktu selesai')) missing.push('Waktu selesai harus setelah waktu mulai');
    if (!solution.trim()) missing.push('Hasil Pekerjaan');
    if (missing.length) {
      setActionError(`Lengkapi bagian wajib: ${missing.join(', ')}.`);
      return;
    }
    setActionError('');
    setShowCloseConfirmation(true);
  }

  async function closeTicket() {
    if (!ticket) return;
    setActionError('');
    setBusy(true);
    try {
      const endedAt = !workDowntimeEndTouched && !ticket.actual_downtime_ended_at
        ? new Date().toISOString()
        : `${workDowntimeEnd}:00+07:00`;
      const input: TicketActionInput = {
        reason: reason.trim(),
        actionTaken: actionTaken.trim(),
        actualDowntimeStartedAt: `${workDowntimeStart}:00+07:00`,
        actualDowntimeEndedAt: endedAt,
        solution: solution.trim(),
      };
      setTicket(await performTicketAction(ticketId, 'close', input));
      setShowCloseConfirmation(false);
      setSuccessMessage('Tiket berhasil diselesaikan dan tersimpan di riwayat.');
    } catch (requestError) {
      setActionError(apiMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function saveDowntimeCorrection() {
    if (!downtimeStart || !downtimeEnd || !downtimeReason.trim()) {
      setActionError('Isi waktu mulai, waktu selesai, dan alasan koreksi.');
      return;
    }
    setDowntimeBusy(true);
    setActionError('');
    try {
      const updated = await correctTicketDowntime(ticketId, {
        startedAt: `${downtimeStart}:00+07:00`,
        endedAt: `${downtimeEnd}:00+07:00`,
        reason: downtimeReason.trim(),
      });
      setTicket(updated);
      setSuccessMessage('Waktu downtime aktual dan Duration berhasil dikoreksi.');
      setDowntimeReason('');
    } catch (requestError) {
      setActionError(apiMessage(requestError));
    } finally {
      setDowntimeBusy(false);
    }
  }

  async function reopenClosedTicket() {
    if (!reopenReason.trim()) {
      setActionError('Alasan pembukaan kembali wajib diisi.');
      return;
    }
    setBusy(true);
    setActionError('');
    try {
      setTicket(await reopenTicket(ticketId, reopenReason.trim()));
      setShowReopenConfirmation(false);
      setReopenReason('');
      setSuccessMessage('Tiket berhasil dibuka kembali. Data Work Detail dan riwayat penutupan tetap tersimpan.');
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

  const hasAnalysis = Boolean(rootCauseAnalysis || correctiveActionPlan || targetAt || actionById || ticket.rootCauseAnalysis || ticket.correctiveActionPlan || ticket.targetAt || ticket.actionBy);
  const verificationLabel = (value?: VerificationValue) => value ?? 'Belum diperiksa';

  return <div className="ticket-detail-layout">
    <main>
      <div className="ticket-detail-hero">
        <div className="ticket-detail-hero__content">
          <Link className="ticket-detail-back" href={role === 'operator' ? '/incidents' : '/tickets'}><ArrowLeft aria-hidden="true" /> {role === 'operator' ? 'Kembali ke Insiden' : 'Kembali ke tiket'}</Link>
          <p className="eyebrow">Tiket Pemeliharaan · {ticket.number}</p>
          <h1>{problemLabels[ticket.problemType] ?? ticket.problemType}</h1>
          <p className="ticket-detail-location">{ticket.plant} · {ticket.machine.code} / {ticket.machine.name}</p>
          <p className="ticket-detail-reporter">Pelapor: {ticket.reporter?.name ?? 'Operator'}</p>
        </div>
        <div className="ticket-detail-status">
          <b className={`ticket-status ticket-status--${ticket.status.toLowerCase()}`}>{ticketStatusLabel(ticket.status)}</b>
          <span>{ticket.status === 'OPEN' ? 'Sedang dikerjakan' : 'Pekerjaan sudah selesai'}</span>
        </div>
      </div>

      <section className="ticket-info-card ticket-summary-card ticket-detail-section ticket-detail-problem">
        <div className="ticket-detail-section-heading"><h2>Masalah</h2><p>Informasi yang perlu diketahui sebelum mulai bekerja.</p></div>
        <dl className="ticket-detail-facts">
          <div><dt>Mesin</dt><dd>{ticket.machine.code} / {ticket.machine.name}</dd></div>
          <div><dt>Masalah</dt><dd>{problemLabels[ticket.problemType] ?? ticket.problemType}</dd></div>
          <div><dt>Sumber</dt><dd>{sourceLabels[ticket.sourceType ?? ''] ?? 'Operator'}</dd></div>
        </dl>
        <div className="ticket-detail-description"><dt>Deskripsi</dt><p>{ticket.description || 'Tidak ada deskripsi tambahan.'}</p></div>
      </section>

      <section className="ticket-info-card work-detail-card ticket-work-section ticket-detail-section ticket-detail-work">
        <div className="ticket-detail-section-heading"><h2>Hasil Pekerjaan</h2><p>Isi informasi pekerjaan yang sudah dilakukan.</p></div>
        {ticket.status === 'OPEN' && canManage ? <>
          <p className="ticket-required-note"><span aria-hidden="true">*</span> Wajib diisi sebelum menyelesaikan tiket.</p>
          <div className="work-detail-form">
            <label className="form-field"><span>Penyebab <b aria-hidden="true">*</b></span><textarea rows={3} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Apa penyebab masalah?" /></label>
            <label className="form-field"><span>Tindakan <b aria-hidden="true">*</b></span><textarea rows={3} value={actionTaken} onChange={(event) => setActionTaken(event.target.value)} placeholder="Apa yang dilakukan?" /></label>
            <div className="work-detail-grid ticket-downtime-grid">
              <label className="form-field ticket-executor-field"><span>Pelaksana <b aria-hidden="true">*</b></span><span className="ticket-executor-value"><UserRound aria-hidden="true" /><input aria-label="Pelaksana" value={currentUser?.name ?? ''} placeholder="Memuat data pengguna..." readOnly /></span></label>
              <label className="form-field"><span>Mulai downtime <b aria-hidden="true">*</b></span><input type="datetime-local" value={workDowntimeStart} onChange={(event) => setWorkDowntimeStart(event.target.value)} /></label>
              <label className="form-field"><span>Selesai downtime <b aria-hidden="true">*</b></span><input type="datetime-local" value={workDowntimeEnd} onChange={(event) => { setWorkDowntimeEnd(event.target.value); setWorkDowntimeEndTouched(true); }} /></label>
            </div>
            <p className="ticket-downtime-help">Otomatis mengikuti waktu tiket diselesaikan. Ubah bila mesin kembali beroperasi pada waktu berbeda.</p>
            {workDowntimeStart && workDowntimeEnd && <p className="ticket-duration-preview" role="status">{previewDowntime(workDowntimeStart, workDowntimeEnd)}</p>}
            <label className="form-field"><span>Hasil Pekerjaan <b aria-hidden="true">*</b></span><textarea rows={3} value={solution} onChange={(event) => setSolution(event.target.value)} placeholder="Bagaimana hasil perbaikannya?" /></label>
          </div>
        </> : <dl className="ticket-detail-facts ticket-detail-work-history">
          <div><dt>Penyebab</dt><dd>{ticket.reason || 'Belum diisi'}</dd></div>
          <div><dt>Tindakan</dt><dd>{ticket.actionTaken || 'Belum diisi'}</dd></div>
          <div><dt>Pelaksana</dt><dd>{ticket.executor?.name || 'Belum ditentukan'}</dd></div>
          <div><dt>Durasi</dt><dd>{ticketDurationLabel(ticket)}</dd></div>
          {ticket.actual_downtime_started_at && ticket.actual_downtime_ended_at && <><div><dt>Downtime aktual mulai</dt><dd>{formatDateTime(ticket.actual_downtime_started_at)}</dd></div><div><dt>Downtime aktual selesai</dt><dd>{formatDateTime(ticket.actual_downtime_ended_at)}</dd></div></>}
          <div className="ticket-detail-fact-wide"><dt>Hasil Pekerjaan</dt><dd>{ticket.solution || 'Belum diisi'}</dd></div>
        </dl>}
      </section>

      {isAdmin && (ticket.status === 'CLOSED' || ticket.durationHours !== undefined || ticket.actual_downtime_started_at || ticket.actual_downtime_ended_at) && <section className="ticket-info-card ticket-detail-section ticket-detail-optional">
        <div className="ticket-detail-section-heading"><div><h2><Clock3 aria-hidden="true" /> Koreksi Waktu Downtime Aktual</h2><p>Waktu ini terpisah dari waktu sistem menutup tiket. Koreksi akan memperbarui Duration dan tersimpan di audit trail.</p></div></div>
        {Boolean(ticket.actual_downtime_started_at) !== Boolean(ticket.actual_downtime_ended_at) ? <p className="ticket-required-note">Timestamp downtime belum lengkap. Lengkapi waktu yang kosong berdasarkan waktu aktual mesin berhenti dan kembali beroperasi.</p> : !ticket.actual_downtime_started_at && <p className="ticket-required-note">Waktu yang tampil sebagai nilai awal hanya perkiraan dari Duration lama dan closed_at. Masukkan waktu aktual mesin berhenti dan kembali beroperasi.</p>}
        <div className="work-detail-grid">
          <label className="form-field">Mulai downtime aktual<input type="datetime-local" value={downtimeStart} onChange={(event) => setDowntimeStart(event.target.value)} /></label>
          <label className="form-field">Selesai downtime aktual<input type="datetime-local" value={downtimeEnd} onChange={(event) => setDowntimeEnd(event.target.value)} /></label>
        </div>
        <label className="form-field">Alasan koreksi wajib diisi<textarea rows={2} value={downtimeReason} onChange={(event) => setDowntimeReason(event.target.value)} placeholder="Contoh: waktu aktual dikonfirmasi dari catatan operator." /></label>
        {actionError && <ErrorState title="Waktu downtime belum disimpan" description={actionError} />}
        <button className="secondary-action" type="button" onClick={() => void saveDowntimeCorrection()} disabled={downtimeBusy}>{downtimeBusy ? 'Menyimpan...' : 'Simpan Koreksi Waktu'}</button>
        {ticket.audits?.length ? <div className="ticket-audit-history"><h3>Riwayat perubahan tiket</h3>{ticket.audits.map((audit) => <article key={audit.id}><strong>{audit.action === 'REOPEN' ? 'Tiket dibuka kembali' : audit.action === 'DOWNTIME_TIME_CORRECTED' ? 'Waktu downtime dikoreksi' : 'Tiket ditutup'}</strong><span>{audit.user?.name ?? 'Pengguna'} · {formatDateTime(audit.created_at)}</span>{audit.reason && <p>Alasan: {audit.reason}</p>}<small>Sebelum: {formatDateTime(String(audit.before_values?.actual_downtime_started_at ?? audit.before_values?.closed_at ?? ''))} → {formatDateTime(String(audit.before_values?.actual_downtime_ended_at ?? audit.before_values?.closed_at ?? ''))} ({String(audit.before_values?.duration_hours ?? '—')} jam)</small><small>Sesudah: {formatDateTime(String(audit.after_values?.actual_downtime_started_at ?? audit.after_values?.closed_at ?? ''))} → {formatDateTime(String(audit.after_values?.actual_downtime_ended_at ?? audit.after_values?.closed_at ?? ''))} ({String(audit.after_values?.duration_hours ?? '—')} jam)</small></article>)}</div> : null}
      </section>}

      <TicketAttachments ticketId={ticket.number} attachments={ticket.attachments} editable={ticket.status === 'OPEN' && canManage} onUploaded={(attachments) => setTicket((current) => current ? { ...current, attachments } : current)} />

      <section className="ticket-info-card spare-parts-card ticket-spare-section ticket-detail-section ticket-detail-optional">
        <div className="ticket-detail-section-heading ticket-detail-section-heading--action"><div><h2><span className="ticket-detail-section-icon"><Wrench aria-hidden="true" /></span>Spare Part <span className="ticket-optional-tag">Opsional</span></h2><p>Tambahkan hanya jika ada spare part yang digunakan.</p></div>{ticket.status === 'OPEN' && canManage && <button className="secondary-action ticket-add-part" type="button" onClick={() => setShowSparePartForm((current) => !current)} disabled={sparePartBusy}><Plus aria-hidden="true" />{showSparePartForm ? 'Batal' : 'Tambah Spare Part'}</button>}</div>
        {ticket.spareParts.length === 0 ? <p className="spare-parts-empty">Belum ada spare part yang digunakan.</p> : <div className="spare-parts-list">{ticket.spareParts.map((part) => <div className="spare-part-row" key={part.id}><div><strong>{part.name}</strong><small>{part.materialCode}{part.remark ? ` · ${part.remark}` : ''}</small></div><span>{part.quantity} digunakan</span>{ticket.status === 'OPEN' && canManage && <button className="icon-button spare-part-remove" type="button" onClick={() => void deleteSparePart(part)} disabled={sparePartBusy} aria-label={`Hapus ${part.name}`}>×</button>}</div>)}</div>}
        {showSparePartForm && ticket.status === 'OPEN' && canManage && <div className="spare-part-form"><label className="form-field">Nama spare part<input value={sparePartName} onChange={(event) => setSparePartName(event.target.value)} placeholder="Contoh: Bearing" /></label><div className="work-detail-grid"><label className="form-field">Kode material<input value={materialCode} onChange={(event) => setMaterialCode(event.target.value)} placeholder="MAT-001" /></label><label className="form-field">Jumlah<input type="number" min="1" step="1" value={quantity} onChange={(event) => setQuantity(event.target.value)} /></label></div><label className="form-field">Keterangan (opsional)<textarea rows={2} value={remark} onChange={(event) => setRemark(event.target.value)} placeholder="Tambahkan keterangan" /></label><button className="primary-button" type="button" onClick={() => void saveSparePart()} disabled={sparePartBusy}>{sparePartBusy ? 'Menyimpan...' : 'Simpan spare part'}</button></div>}
      </section>

      <section className="ticket-info-card breakdown-card ticket-breakdown-section ticket-detail-section ticket-detail-optional">
        <details className="ticket-analysis-details" open={hasAnalysis}>
          <summary className="ticket-detail-section-heading"><div><h2>Analisa Kerusakan</h2><p>Isi jika pekerjaan membutuhkan analisa lebih lanjut.</p></div><span className="ticket-optional-tag">Opsional</span></summary>
          {ticket.status === 'OPEN' && canManage ? <div className="breakdown-form"><label className="form-field">Analisa Akar Masalah<textarea rows={3} value={rootCauseAnalysis} onChange={(event) => setRootCauseAnalysis(event.target.value)} placeholder="Apa penyebab utama kerusakan?" /></label><label className="form-field">Rencana Tindakan Perbaikan<textarea rows={3} value={correctiveActionPlan} onChange={(event) => setCorrectiveActionPlan(event.target.value)} placeholder="Apa rencana perbaikannya?" /></label><div className="work-detail-grid"><label className="form-field">Target<input type="datetime-local" value={targetAt} onChange={(event) => setTargetAt(event.target.value)} /></label><label className="form-field">Pelaksana<select value={actionById} onChange={(event) => setActionById(event.target.value)}><option value="">Pilih pelaksana</option>{maintenanceUsers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}</select></label></div><button className="secondary-action" type="button" onClick={() => void saveAnalysis()} disabled={analysisBusy}>{analysisBusy ? 'Menyimpan...' : 'Simpan analisa'}</button></div> : <dl className="ticket-detail-facts ticket-detail-work-history"><div className="ticket-detail-fact-wide"><dt>Analisa Akar Masalah</dt><dd>{ticket.rootCauseAnalysis || 'Belum diisi'}</dd></div><div className="ticket-detail-fact-wide"><dt>Rencana Tindakan Perbaikan</dt><dd>{ticket.correctiveActionPlan || 'Belum diisi'}</dd></div><div><dt>Target</dt><dd>{ticket.targetAt ? formatDateTime(ticket.targetAt) : 'Belum ditentukan'}</dd></div><div><dt>Pelaksana</dt><dd>{ticket.actionBy?.name || 'Belum ditentukan'}</dd></div></dl>}
        </details>
      </section>

      <section className="ticket-info-card verification-card ticket-detail-section ticket-detail-optional">
        <div className="ticket-detail-section-heading"><h2>Pemeriksaan Setelah Perbaikan</h2><p>Pastikan mesin aman dan berfungsi. Bagian ini opsional.</p></div>
        {ticket.status === 'CLOSED' || !canManage ? <div className="verification-history-list">{verificationItems.map((item) => <div className="verification-row" key={item.key}><strong>{item.label}</strong><span>{verificationLabel(verification[item.key])}</span></div>)}</div> : <div className="verification-list">{verificationItems.map((item) => <div className="verification-row" key={item.key}><strong>{item.label}</strong><div className="verification-options">{(['OK', 'NOK', 'N/A'] as VerificationValue[]).map((value) => <label className={`verification-option verification-option--${value.toLowerCase().replace('/', '-')}${verification[item.key] === value ? ' verification-option--selected' : ''}`} key={value}><input type="radio" name={`verification-${item.key}`} value={value} checked={verification[item.key] === value} onChange={() => setVerification((current) => ({ ...current, [item.key]: value }))} disabled={verificationBusy} /><span>{value}</span></label>)}</div></div>)}</div>}
        {ticket.status === 'OPEN' && canManage && <button className="secondary-action verification-save" type="button" onClick={() => void saveVerification()} disabled={verificationBusy}>{verificationBusy ? 'Menyimpan...' : 'Simpan pemeriksaan'}</button>}
      </section>

      {ticket.status === 'OPEN' && canManage && <section className="ticket-complete-panel">
        <div><h2>Selesaikan Tiket</h2><p>Pastikan hasil pekerjaan sudah lengkap sebelum menyelesaikan tiket.</p></div>
        {actionError && <ErrorState title="Periksa kembali data pekerjaan" description={actionError} />}
        <button className="primary-button ticket-complete-button" type="button" onClick={requestCloseTicket} disabled={busy}>{busy ? 'Menyimpan...' : <><ClipboardCheck aria-hidden="true" />Selesaikan Tiket</>}</button>
      </section>}

      <section className="ticket-info-card closed-history-card ticket-detail-section ticket-detail-history">
        <div className="ticket-detail-section-heading"><div><h2>{role === 'operator' ? 'Penyelesaian' : 'Riwayat'}</h2><p>Catatan status dan penyelesaian tiket.</p></div>{isAdmin && ticket.status === 'CLOSED' && <button className="secondary-action" type="button" onClick={() => { setActionError(''); setShowReopenConfirmation(true); }}><RotateCcw aria-hidden="true" /> Buka Kembali Tiket</button>}</div>
        <dl className="ticket-detail-facts">
          <div><dt>Status</dt><dd><span className={`ticket-status ticket-status--${ticket.status.toLowerCase()}`}>{ticketStatusLabel(ticket.status)}</span></dd></div>
          {ticket.status === 'CLOSED' ? <><div><dt>Diselesaikan pada</dt><dd>{formatDateTime(ticket.closed_at)}</dd></div><div><dt>Diselesaikan oleh</dt><dd>{ticket.closed_by?.name || '—'}</dd></div></> : <><div><dt>Dibuat pada</dt><dd>{formatDateTime(ticket.createdAt)}</dd></div><div><dt>Pelapor</dt><dd>{ticket.reporter?.name ?? 'Operator'}</dd></div></>}
        </dl>
      </section>
    </main>

    {showCloseConfirmation && <div className="machine-modal-backdrop" role="presentation" onClick={() => setShowCloseConfirmation(false)}><section className="machine-modal ticket-close-confirmation" role="alertdialog" aria-modal="true" aria-labelledby="ticket-close-title" aria-describedby="ticket-close-description" onClick={(event) => event.stopPropagation()}><h2 id="ticket-close-title">Selesaikan tiket ini?</h2><p id="ticket-close-description">Setelah diselesaikan, data pekerjaan menjadi riwayat dan tidak dapat diubah.</p>{actionError && <ErrorState title="Tiket belum diselesaikan" description={actionError} />}<div className="machine-modal-actions"><button className="secondary-action" type="button" onClick={() => setShowCloseConfirmation(false)} disabled={busy}>Batal</button><button className="primary-button" type="button" onClick={() => void closeTicket()} disabled={busy}>{busy ? 'Menyimpan...' : 'Selesaikan Tiket'}</button></div></section></div>}
    {showReopenConfirmation && <div className="machine-modal-backdrop" role="presentation" onClick={() => !busy && setShowReopenConfirmation(false)}><section className="machine-modal ticket-close-confirmation" role="alertdialog" aria-modal="true" aria-labelledby="ticket-reopen-title" onClick={(event) => event.stopPropagation()}><h2 id="ticket-reopen-title">Buka kembali tiket?</h2><p>Status berubah menjadi OPEN agar Work Detail dapat diperbaiki. Riwayat penutupan dan durasi downtime tetap tersimpan.</p><label className="form-field">Alasan pembukaan kembali wajib diisi<textarea rows={3} value={reopenReason} onChange={(event) => setReopenReason(event.target.value)} placeholder="Jelaskan alasan tiket dibuka kembali." /></label>{actionError && <ErrorState title="Tiket belum dibuka kembali" description={actionError} />}<div className="machine-modal-actions"><button className="secondary-action" type="button" onClick={() => setShowReopenConfirmation(false)} disabled={busy}>Batal</button><button className="primary-button" type="button" onClick={() => void reopenClosedTicket()} disabled={busy}>{busy ? 'Menyimpan...' : 'Konfirmasi Buka Kembali'}</button></div></section></div>}
    {successMessage && <div className="machine-modal-backdrop" role="presentation"><section className="machine-modal machine-success-modal" role="alertdialog" aria-modal="true" aria-labelledby="ticket-action-success-title"><span className="machine-success-icon">✓</span><h2 id="ticket-action-success-title">{successMessage.startsWith('Tiket berhasil ditutup') ? 'Tiket berhasil diselesaikan' : 'Berhasil'}</h2><p>{successMessage}</p><div className="machine-modal-actions"><button className="primary-button" type="button" onClick={() => setSuccessMessage('')}>OK</button></div></section></div>}
  </div>;
}
