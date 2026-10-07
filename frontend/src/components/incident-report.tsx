'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, Check, CircleAlert, CircleCheck, ClipboardCheck, Cog, Factory, Plus, RefreshCw, Radio, RotateCcw, Search, Settings2, Volume2, Wrench, X } from 'lucide-react';
import { createIncident, getIncidents, getMachines, getPlantOptions, type PlantOption } from '@/lib/maintenance-api';
import type { Incident, Machine, ResolvedMachineQr } from '@/lib/maintenance-types';
import { DataPagination, EmptyState, ErrorState, MaintenanceTable, PaginationSkeleton, Skeleton, TableSkeleton } from './ui';
import { MediaAttachmentPicker } from './media-attachment-picker';
import { ProtectedAttachmentLink } from './protected-attachment-link';

const problemTypes = [
  { value: 'Machine stopped', label: 'Mesin berhenti', detail: 'Mesin tidak beroperasi', icon: Settings2 },
  { value: 'Abnormal sound', label: 'Suara tidak normal', detail: 'Terdengar suara yang tidak biasa', icon: Volume2 },
  { value: 'Sensor problem', label: 'Masalah sensor', detail: 'Sensor tidak bekerja normal', icon: Radio },
  { value: 'Quality problem', label: 'Masalah mutu', detail: 'Ditemukan masalah pada hasil produksi', icon: AlertTriangle },
  { value: 'Other', label: 'Lainnya', detail: 'Masalah lainnya', icon: CircleAlert },
] as const;

function incidentStatusLabel(status?: string) {
  if (status === 'RESOLVED') return 'Selesai';
  if (status === 'OPEN') return status;
  return status ?? 'Tidak diketahui';
}

type Resolution = 'resolved' | 'cannot-resolve' | '';
type QrPrefill = ResolvedMachineQr & { payload: string };

export function IncidentReport() {
  const [plants, setPlants] = useState<PlantOption[]>([]);
  const [machines, setMachines] = useState<Machine[]>([]);
  const [machineDirectory, setMachineDirectory] = useState<Machine[]>([]);
  const [incidents, setIncidents] = useState<Incident[]>([]);
  const [selectedIncident, setSelectedIncident] = useState<Incident | null>(null);
  const [plantId, setPlantId] = useState('');
  const [machineId, setMachineId] = useState('');
  const [problemType, setProblemType] = useState('');
  const [description, setDescription] = useState('');
  const [resolution, setResolution] = useState<Resolution>('');
  const [actionTaken, setActionTaken] = useState('');
  const [result, setResult] = useState('');
  const [attachmentFiles, setAttachmentFiles] = useState<File[]>([]);
  const [plantsLoading, setPlantsLoading] = useState(true);
  const [machinesLoading, setMachinesLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [successOpen, setSuccessOpen] = useState(false);
  const [successTicketNumber, setSuccessTicketNumber] = useState('');
  const [touched, setTouched] = useState(false);
  const [historyLoading, setHistoryLoading] = useState(true);
  const [historyError, setHistoryError] = useState('');
  const [reportOpen, setReportOpen] = useState(false);
  const [qrPrefill, setQrPrefill] = useState<QrPrefill | null>(null);
  const submitLock = useRef(false);
  const [historySearchInput, setHistorySearchInput] = useState('');
  const [historySearch, setHistorySearch] = useState('');
  const [historyPlant, setHistoryPlant] = useState('');
  const [historyMachine, setHistoryMachine] = useState('');
  const [historyStatus, setHistoryStatus] = useState('');
  const [historyProblemType, setHistoryProblemType] = useState('');
  const [historySort, setHistorySort] = useState<'newest' | 'oldest'>('newest');
  const [historyPage, setHistoryPage] = useState(1);
  const [historyPageSize, setHistoryPageSize] = useState(10);

  async function refreshHistory() {
    setHistoryLoading(true);
    try {
      setIncidents(await getIncidents());
      setHistoryError('');
    } catch (reason) {
      setHistoryError(reason instanceof Error ? reason.message : 'Riwayat insiden tidak dapat dimuat.');
    } finally {
      setHistoryLoading(false);
    }
  }

  function acknowledgeSuccess() {
    setSuccessOpen(false);
    setSuccessTicketNumber('');
    setReportOpen(false);
    setPlantId('');
    setMachineId('');
    setProblemType('');
    setDescription('');
    setResolution('');
    setActionTaken('');
    setResult('');
    setAttachmentFiles([]);
    setQrPrefill(null);
    setTouched(false);
    void refreshHistory();
  }

  useEffect(() => {
    getPlantOptions()
      .then(setPlants)
      .catch((reason: unknown) => setError(reason instanceof Error ? reason.message : 'Tidak dapat memuat daftar plant.'))
      .finally(() => setPlantsLoading(false));
    getMachines({ page: 1, per_page: 100 }).then(setMachineDirectory).catch(() => setMachineDirectory([]));
  }, []);

  useEffect(() => { void refreshHistory(); }, []);

  useEffect(() => {
    const openReport = (event: Event) => {
      const detail = (event as CustomEvent<QrPrefill | undefined>).detail;
      setReportOpen(true);
      setError('');
      setTouched(false);
      if (detail) {
        setQrPrefill(detail);
        setPlantId(String(detail.plant.id));
        setMachineId(String(detail.machine.id));
        setPlants((current) => current.some((plant) => String(plant.id) === String(detail.plant.id)) ? current : [...current, detail.plant as PlantOption]);
      } else {
        setQrPrefill(null);
        setPlantId('');
        setMachineId('');
      }
    };
    window.addEventListener('maintenance:open-incident-report', openReport);
    if (window.sessionStorage.getItem('maintenance:open-incident-report') === '1') {
      window.sessionStorage.removeItem('maintenance:open-incident-report');
      const stored = window.sessionStorage.getItem('maintenance:prefill-incident-qr');
      window.sessionStorage.removeItem('maintenance:prefill-incident-qr');
      try {
        const detail = stored ? JSON.parse(stored) as QrPrefill : undefined;
        openReport(new CustomEvent('maintenance:open-incident-report', { detail }));
      } catch {
        openReport(new Event('maintenance:open-incident-report'));
      }
    }
    return () => window.removeEventListener('maintenance:open-incident-report', openReport);
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => { setHistorySearch(historySearchInput.trim()); setHistoryPage(1); }, 350);
    return () => window.clearTimeout(timer);
  }, [historySearchInput]);

  useEffect(() => { setHistoryPage(1); }, [historyPlant, historyMachine, historyStatus, historyProblemType, historySort]);

  useEffect(() => {
    if (qrPrefill) {
      const machine: Machine = { ...qrPrefill.machine, section: qrPrefill.machine.section ?? undefined, plant_id: qrPrefill.plant.id, is_active: true };
      setMachines([machine]);
      setMachineId(String(machine.id));
      setMachinesLoading(false);
      return;
    }
    setMachineId('');
    setMachines([]);
    if (!plantId) return;
    setMachinesLoading(true);
    setError('');
    getMachines({ plant: plantId, page: 1, per_page: 100 })
      .then(setMachines)
      .catch((reason: unknown) => setError(reason instanceof Error ? reason.message : 'Tidak dapat memuat mesin pada plant ini.'))
      .finally(() => setMachinesLoading(false));
  }, [plantId, qrPrefill]);

  const selectedPlant = useMemo(() => qrPrefill?.plant ?? plants.find((plant) => String(plant.id) === plantId), [plantId, plants, qrPrefill]);
  const filteredIncidents = useMemo(() => {
    const search = historySearch.toLowerCase();
    return incidents.filter((incident) => {
      const machine = machineDirectory.find((item) => String(item.id) === String(incident.machineId));
      const matchesSearch = !search || [incident.problemType, incident.description, machine?.code, machine?.name].filter(Boolean).some((value) => String(value).toLowerCase().includes(search));
      return matchesSearch && (!historyPlant || String(incident.plantId) === historyPlant) && (!historyMachine || String(incident.machineId) === historyMachine) && (!historyStatus || incident.status === historyStatus) && (!historyProblemType || incident.problemType === historyProblemType);
    }).sort((left, right) => historySort === 'newest' ? new Date(right.createdAt).getTime() - new Date(left.createdAt).getTime() : new Date(left.createdAt).getTime() - new Date(right.createdAt).getTime());
  }, [historyMachine, historyPlant, historyProblemType, historySearch, historySort, historyStatus, incidents, machineDirectory]);
  const hasActiveHistoryFilters = Boolean(historySearchInput.trim() || historyPlant || historyMachine || historyStatus || historyProblemType || historySort !== 'newest');
  const totalHistoryPages = Math.max(1, Math.ceil(filteredIncidents.length / historyPageSize));
  const visibleIncidents = filteredIncidents.slice((historyPage - 1) * historyPageSize, historyPage * historyPageSize);
  function validate() {
    if (!plantId) return 'Pilih plant terlebih dahulu.';
    if (!machineId) return 'Pilih mesin terlebih dahulu.';
    if (!problemType) return 'Pilih jenis masalah.';
    if (problemType === 'Other' && description.trim().length < 10) return 'Untuk jenis masalah Lainnya, jelaskan masalah minimal 10 karakter.';
    if (!resolution) return 'Pilih apakah masalah dapat diselesaikan operator.';
    if (resolution === 'resolved' && actionTaken.trim().length < 5) return 'Jelaskan tindakan operator minimal 5 karakter.';
    if (resolution === 'resolved' && result.trim().length < 5) return 'Jelaskan hasil tindakan minimal 5 karakter.';
    return '';
  }

  function resetHistoryFilters() {
    setHistorySearchInput('');
    setHistorySearch('');
    setHistoryPlant('');
    setHistoryMachine('');
    setHistoryStatus('');
    setHistoryProblemType('');
    setHistorySort('newest');
    setHistoryPage(1);
  }

  function changeHistoryPageSize(pageSize: number) {
    setHistoryPageSize(pageSize);
    setHistoryPage(1);
    void refreshHistory();
  }

  async function submitReport() {
    if (submitLock.current) return;
    setTouched(true);
    const validationError = validate();
    if (validationError) {
      setError(validationError);
      return;
    }
    setError('');
    submitLock.current = true;
    setSubmitting(true);
    try {
      if (resolution === 'resolved') {
        await createIncident({ plantId, machineId, problemType, description: description.trim(), actionTaken: actionTaken.trim(), result: result.trim(), status: 'RESOLVED', qrPayload: qrPrefill?.payload, files: attachmentFiles });
        setSuccessTicketNumber('');
        setSuccessOpen(true);
        setReportOpen(false);
      } else {
        const createdIncident = await createIncident({ plantId, machineId, problemType, description: description.trim(), status: 'OPEN', qrPayload: qrPrefill?.payload, files: attachmentFiles });
        const ticketNumber = createdIncident.ticketNumber;
        if (!ticketNumber) throw new Error('Ticket berhasil dibuat, tetapi nomor ticket tidak tersedia dari API.');
        setSuccessTicketNumber(ticketNumber);
        setSuccessOpen(true);
        setReportOpen(false);
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Laporan tidak dapat disimpan. Periksa koneksi API lalu coba lagi.');
    } finally {
      submitLock.current = false;
      setSubmitting(false);
    }
  }

  const validationError = touched ? validate() : '';
  const descriptionError = touched && problemType === 'Other' && description.trim().length < 10 ? 'Minimal 10 karakter untuk jenis masalah Lainnya.' : '';
  const selectedMachine: Machine | undefined = qrPrefill
    ? { ...qrPrefill.machine, section: qrPrefill.machine.section ?? undefined, plant_id: qrPrefill.plant.id, is_active: true }
    : machines.find((machine) => String(machine.id) === machineId);

  return <>
  {successOpen && <div className="incident-modal-backdrop incident-success-backdrop" role="presentation"><section className="incident-success-modal" role="alertdialog" aria-modal="true" aria-labelledby="incident-success-title"><CircleCheck className="incident-success-icon" aria-hidden="true" /><h2 id="incident-success-title">Laporan Berhasil Dibuat</h2><p>Laporan insiden berhasil dibuat.</p>{successTicketNumber ? <><p>Tiket Maintenance berhasil dibuat.</p><div className="ticket-success-number"><span>Nomor Tiket Maintenance</span><strong>{successTicketNumber}</strong></div></> : <p>Tidak ada tiket maintenance yang dibuat.</p>}<button className="primary-button" type="button" onClick={acknowledgeSuccess}>OK</button></section></div>}
  {!plantsLoading && plants.length === 0 && !error && <EmptyState title="Tidak ada Plant tersedia" description="Plant belum tersedia. Riwayat insiden tetap dapat ditinjau." />}
  {reportOpen && <div className="incident-modal-backdrop incident-form-backdrop" role="dialog" aria-modal="true" aria-labelledby="incident-form-title"><section className="incident-form-modal"><div className="incident-form-modal__header"><div><p className="eyebrow">Laporkan masalah pada mesin.</p><h2 id="incident-form-title">Buat Laporan Insiden</h2></div><button className="icon-button" type="button" onClick={() => setReportOpen(false)} aria-label="Tutup form insiden"><X aria-hidden="true" /></button></div><div className="incident-form-modal__body"><div className="report-layout">
    <div className="report-main">
      <div className="stepper"><span className="stepper__active">01 Lokasi</span><span className={plantId && machineId ? 'stepper__active' : ''}>02 Masalah</span><span className={resolution ? 'stepper__active' : ''}>03 Tindakan awal</span></div>
      <section className="form-card incident-form-section">
        <div className="incident-section-heading"><span className="incident-section-icon"><Factory aria-hidden="true" /></span><div><p className="eyebrow">Langkah 1</p><h2>Pilih lokasi dan mesin</h2><p>Pilih plant dan mesin yang mengalami masalah.</p></div></div>
        <div className="form-grid-two incident-location-grid">
          <label className="form-field"><span className="incident-field-label"><Factory aria-hidden="true" />Plant</span>{qrPrefill ? <input value={`${qrPrefill.plant.code} - ${qrPrefill.plant.name}`} readOnly aria-readonly="true" /> : plantsLoading ? <Skeleton className="incident-select-skeleton" /> : <select value={plantId} onChange={(event) => setPlantId(event.target.value)} disabled={submitting}><option value="">Pilih plant</option>{plants.map((plant) => <option key={plant.id} value={plant.id}>{plant.code} - {plant.name}</option>)}</select>}</label>
          <label className="form-field"><span className="incident-field-label"><Cog aria-hidden="true" />Mesin</span>{qrPrefill ? <input value={`${qrPrefill.machine.code} - ${qrPrefill.machine.name}`} readOnly aria-readonly="true" /> : machinesLoading ? <Skeleton className="incident-select-skeleton" /> : <select value={machineId} onChange={(event) => setMachineId(event.target.value)} disabled={!plantId || submitting}><option value="">{!plantId ? 'Pilih plant terlebih dahulu' : 'Pilih mesin'}</option>{machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.code} - {machine.name}</option>)}</select>}</label>
        </div>
        {selectedPlant && <p className="form-helper">{qrPrefill ? 'Mesin dan Plant ditentukan dari QR Code.' : `Menampilkan ${machines.length} mesin pada ${selectedPlant.name}.`}</p>}
        {plantId && !machinesLoading && !machines.length && <p className="form-inline-error">Tidak ada mesin aktif pada plant ini.</p>}
      </section>
      <section className="form-card incident-form-section">
        <div className="incident-section-heading"><span className="incident-section-icon"><CircleAlert aria-hidden="true" /></span><div><p className="eyebrow">Langkah 2</p><h2>Apa masalahnya?</h2><p>Pilih jenis masalah dan jelaskan kondisi yang terjadi.</p></div></div>
        <div className="form-field"><span className="incident-field-label"><ClipboardCheck aria-hidden="true" />Jenis masalah</span><div className="choice-grid incident-choice-grid">{problemTypes.map((type) => { const Icon = type.icon; return <button type="button" className={`choice-button incident-choice-card ${problemType === type.value ? 'choice-button--selected' : ''}`} key={type.value} onClick={() => setProblemType(type.value)} disabled={submitting}><Icon aria-hidden="true" /><span><strong>{type.label}</strong><small>{type.detail}</small></span></button>; })}</div></div>
        <label className="form-field incident-description-field"><span className="incident-field-label">Deskripsi Masalah{problemType === 'Other' && ' (wajib)'}</span><textarea value={description} onChange={(event) => setDescription(event.target.value)} onBlur={() => setTouched(true)} rows={3} placeholder="Jelaskan apa yang terjadi pada mesin..." disabled={submitting} /><span className="form-helper">Berikan informasi singkat agar Maintenance dapat memahami masalah.</span>{descriptionError && <span className="form-inline-error">{descriptionError}</span>}</label>
        <div className="form-field"><span className="incident-field-label">Foto atau video kondisi mesin</span><MediaAttachmentPicker files={attachmentFiles} onChange={setAttachmentFiles} disabled={submitting} /></div>
      </section>
      <section className="form-card incident-form-section">
        <div className="incident-section-heading"><span className="incident-section-icon"><Wrench aria-hidden="true" /></span><div><p className="eyebrow">Langkah 3</p><h2>Tindakan awal</h2><p>Apakah masalah dapat ditangani Operator?</p></div></div>
        <div className="resolution-grid incident-resolution-grid"><button type="button" className={`resolution-button incident-resolution-card ${resolution === 'resolved' ? 'resolution-button--selected' : ''}`} onClick={() => setResolution('resolved')} disabled={submitting}><Check aria-hidden="true" /><span><strong>Dapat diselesaikan Operator</strong><small>Operator dapat menangani masalah ini.</small></span></button><button type="button" className={`resolution-button incident-resolution-card ${resolution === 'cannot-resolve' ? 'resolution-button--selected' : ''}`} onClick={() => setResolution('cannot-resolve')} disabled={submitting}><Wrench aria-hidden="true" /><span><strong>Tidak dapat diselesaikan</strong><small>Masalah akan diteruskan ke Maintenance.</small></span></button></div>
        {resolution === 'resolved' && <div className="follow-up-fields"><label className="form-field">Tindakan yang dilakukan<textarea value={actionTaken} onChange={(event) => setActionTaken(event.target.value)} rows={3} placeholder="Jelaskan tindakan operator." disabled={submitting} /></label><label className="form-field">Hasil tindakan<textarea value={result} onChange={(event) => setResult(event.target.value)} rows={3} placeholder="Jelaskan hasil setelah tindakan." disabled={submitting} /></label></div>}
      </section>
      {validationError && <p className="form-inline-error" role="alert">{validationError}</p>}
      {error && <ErrorState title="Laporan belum tersimpan" description="Periksa kembali data laporan, lalu coba lagi." onRetry={() => void submitReport()} />}
    </div>
    <aside className="report-aside"><p className="eyebrow">Ringkasan</p><h2>{selectedPlant?.name ?? 'Pilih plant'}</h2><p>{selectedMachine?.name ?? 'Pilih mesin untuk melanjutkan laporan.'}</p></aside>
  </div></div><div className="incident-form-modal__footer"><button className="secondary-action" type="button" onClick={() => setReportOpen(false)} disabled={submitting}>Batal</button><button className="primary-button" type="button" onClick={() => void submitReport()} disabled={submitting || machinesLoading}><ClipboardCheck aria-hidden="true" />{submitting ? attachmentFiles.length ? 'Mengunggah...' : 'Menyimpan...' : 'Simpan Laporan'}</button></div></section></div>}
  <section className="incident-history">
    <div className="incident-history-heading">
      <div><p className="eyebrow">Catatan tersimpan</p><h2>Riwayat Insiden</h2><p>Daftar laporan masalah.</p></div>
      <div className="incident-history-actions"><button className="primary-button incident-open-button" type="button" onClick={() => setReportOpen(true)}><Plus aria-hidden="true" /><span className="incident-open-button__desktop-label">Buat Laporan</span><span className="incident-open-button__mobile-label">Buat Laporan Insiden</span></button><button className="secondary-action incident-refresh-button" type="button" onClick={() => void refreshHistory()} disabled={historyLoading} aria-label="Muat ulang" title="Muat ulang"><RefreshCw aria-hidden="true" /><span className="incident-refresh-button__desktop-label">Muat ulang</span></button></div>
    </div>
    <div className="incident-history-filters">
      <label className="incident-filter-search"><Search aria-hidden="true" /><input aria-label="Cari masalah atau mesin" value={historySearchInput} onChange={(event) => setHistorySearchInput(event.target.value)} placeholder="Cari masalah atau mesin..." /></label>
      <label>Plant<select value={historyPlant} onChange={(event) => { setHistoryPlant(event.target.value); setHistoryMachine(''); }}><option value="">Semua Plant</option>{plants.map((plant) => <option key={plant.id} value={plant.id}>{plant.code} — {plant.name}</option>)}</select></label>
      <label>Mesin<select value={historyMachine} onChange={(event) => setHistoryMachine(event.target.value)}><option value="">Semua mesin</option>{machineDirectory.filter((machine) => !historyPlant || String(machine.plant_id) === historyPlant).map((machine) => <option key={machine.id} value={machine.id}>{machine.code} — {machine.name}</option>)}</select></label>
      <label>Status<select value={historyStatus} onChange={(event) => setHistoryStatus(event.target.value)}><option value="">Semua status</option><option value="OPEN">Terbuka</option><option value="RESOLVED">Selesai</option></select></label>
      <details className={`incident-more-filters${historyProblemType || historySort !== 'newest' ? ' incident-more-filters--active' : ''}`}>
        <summary><Settings2 aria-hidden="true" /><span>Filter lainnya</span></summary>
        <div><label>Jenis masalah<select value={historyProblemType} onChange={(event) => setHistoryProblemType(event.target.value)}><option value="">Semua jenis</option>{problemTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select></label><label>Urutan<select value={historySort} onChange={(event) => setHistorySort(event.target.value as 'newest' | 'oldest')}><option value="newest">Terbaru</option><option value="oldest">Terlama</option></select></label></div>
      </details>
      {hasActiveHistoryFilters && <button className="secondary-action incident-reset-filter filter-reset-button" type="button" onClick={resetHistoryFilters}><RotateCcw aria-hidden="true" /><span>Reset filter</span></button>}
    </div>
    {historyError ? <ErrorState title="Data tidak dapat dimuat" description="Coba lagi beberapa saat." onRetry={() => void refreshHistory()} /> : historyLoading ? <><PaginationSkeleton className="ticket-pagination ticket-pagination-skeleton incident-pagination maintenance-pagination-skeleton" /><TableSkeleton className="incident-table-wrap" tableClassName="incident-table" headers={["Tanggal", "Masalah", "Plant / Mesin", "Status", "Maintenance Ticket"]} rows={6} /></> : visibleIncidents.length === 0 ? <div className="incident-empty-state"><ClipboardCheck aria-hidden="true" /><strong>Belum ada laporan insiden</strong><p>{historySearch || historyPlant || historyMachine || historyStatus || historyProblemType ? 'Belum ada laporan yang sesuai dengan filter.' : 'Belum ada laporan yang tersimpan.'}</p>{(historySearch || historyPlant || historyMachine || historyStatus || historyProblemType) && <button className="secondary-action" type="button" onClick={resetHistoryFilters}>Reset Filter</button>}</div> : <>
      <DataPagination currentPage={historyPage} totalPages={totalHistoryPages} onPageChange={setHistoryPage} pageSize={historyPageSize} onPageSizeChange={changeHistoryPageSize} summary={`Menampilkan ${(historyPage - 1) * historyPageSize + 1}–${Math.min(historyPage * historyPageSize, filteredIncidents.length)} dari ${filteredIncidents.length} insiden`} mobileSummary={`${(historyPage - 1) * historyPageSize + 1}–${Math.min(historyPage * historyPageSize, filteredIncidents.length)} dari ${filteredIncidents.length}`} />
      <MaintenanceTable containerClassName="incident-table-wrap" className="incident-table" label="Riwayat insiden"><thead><tr><th>Tanggal</th><th>Masalah</th><th>Plant / Mesin</th><th>Status</th><th>Nomor Tiket</th></tr></thead><tbody>{visibleIncidents.map((incident) => { const machine = machineDirectory.find((item) => String(item.id) === String(incident.machineId)); const ticketNumber = incident.ticketNumber; return <tr className={!ticketNumber ? 'incident-table__row--no-ticket' : undefined} key={incident.id} onClick={() => setSelectedIncident(incident)}><td>{incident.createdAt ? new Date(incident.createdAt).toLocaleString('id-ID') : '-'}</td><td><strong>{problemTypes.find((type) => type.value === incident.problemType)?.label ?? incident.problemType}</strong><small>{incident.description || 'Tidak ada deskripsi tambahan.'}</small></td><td><strong>{plants.find((plant) => String(plant.id) === String(incident.plantId))?.name ?? `Plant ${incident.plantId ?? '—'}`}</strong><small>{machine?.name ?? `Mesin ${incident.machineId ?? '—'}`}</small></td><td><b className={`incident-status-badge incident-status-badge--${incident.status?.toLowerCase() ?? 'unknown'}`}>{incidentStatusLabel(incident.status)}</b></td><td>{ticketNumber ? <Link className="incident-ticket-badge" href={`/tickets/${encodeURIComponent(ticketNumber)}`} title={ticketNumber} onClick={(event) => event.stopPropagation()}>{ticketNumber}</Link> : <span className="incident-ticket-missing">—</span>}</td></tr>; })}</tbody></MaintenanceTable>
  </>}    {selectedIncident && <div className="incident-modal-backdrop" role="presentation" onClick={() => setSelectedIncident(null)}><section className="incident-modal" role="dialog" aria-modal="true" aria-label="Detail insiden" onClick={(event) => event.stopPropagation()}><div className="incident-detail-heading"><div><p className="eyebrow">Detail insiden #{selectedIncident.id}</p><h3>{problemTypes.find((type) => type.value === selectedIncident.problemType)?.label ?? selectedIncident.problemType}</h3></div><button className="icon-button" type="button" onClick={() => setSelectedIncident(null)} aria-label="Tutup detail insiden">×</button></div><dl className="incident-detail-grid"><div><dt>Plant</dt><dd>{plants.find((plant) => String(plant.id) === String(selectedIncident.plantId))?.name ?? `Plant #${selectedIncident.plantId ?? '-'}`}</dd></div><div><dt>Mesin</dt><dd>{machineDirectory.find((machine) => String(machine.id) === String(selectedIncident.machineId))?.name ?? `Mesin #${selectedIncident.machineId ?? '-'}`}</dd></div><div><dt>Waktu</dt><dd>{selectedIncident.createdAt ? new Date(selectedIncident.createdAt).toLocaleString('id-ID') : '-'}</dd></div><div><dt>Status</dt><dd>{selectedIncident.status === 'RESOLVED' ? 'Selesai' : selectedIncident.status ?? 'Tidak diketahui'}</dd></div><div className="incident-detail-wide"><dt>Deskripsi</dt><dd>{selectedIncident.description || '-'}</dd></div><div><dt>Tindakan</dt><dd>{selectedIncident.actionTaken || '-'}</dd></div><div><dt>Hasil</dt><dd>{selectedIncident.result || '-'}</dd></div>{selectedIncident.attachments?.length ? <div className="incident-detail-wide"><dt>Foto/Video</dt><dd><ul className="media-attachment-files">{selectedIncident.attachments.map((attachment) => <li key={attachment.id}><ProtectedAttachmentLink url={attachment.url} fileName={attachment.file_name} /><small>{(attachment.file_size / 1024 / 1024).toFixed(1)} MB</small></li>)}</ul></dd></div> : null}</dl></section></div>}
  </section>
  </>;
}
