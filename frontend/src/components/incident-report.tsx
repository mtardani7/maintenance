'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, Check, CircleAlert, CircleCheck, ClipboardCheck, Cog, Factory, Plus, RefreshCw, Radio, RotateCcw, Search, Settings2, Volume2, Wrench, X } from 'lucide-react';
import { createIncident, getIncidents, getMachines, getPlantOptions, type PlantOption } from '@/lib/maintenance-api';
import type { Incident, Machine, ResolvedMachineQr } from '@/lib/maintenance-types';
import { DataPagination, EmptyState, ErrorState, MaintenanceTable, PaginationSkeleton, Skeleton, TableSkeleton } from './ui';
import { MediaAttachmentPicker } from './media-attachment-picker';
import { ProtectedAttachmentLink } from './protected-attachment-link';
import { useAuthUser } from './auth-boundary';

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
type IncidentField = 'plant' | 'machine' | 'problemType' | 'description' | 'attachments' | 'resolution' | 'actionTaken' | 'result';
type IncidentFieldErrors = Partial<Record<IncidentField, string>>;

const reportSteps = ['Lokasi', 'Masalah', 'Tindakan awal', 'Periksa kembali'] as const;

export function IncidentReport() {
  const authUser = useAuthUser();
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
  const [formStep, setFormStep] = useState(0);
  const [fieldErrors, setFieldErrors] = useState<IncidentFieldErrors>({});
  const [historyLoading, setHistoryLoading] = useState(true);
  const [historyError, setHistoryError] = useState('');
  const [reportOpen, setReportOpen] = useState(false);
  const [qrPrefill, setQrPrefill] = useState<QrPrefill | null>(null);
  const submitLock = useRef(false);
  const reportBodyRef = useRef<HTMLDivElement>(null);
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
    setFormStep(0);
    setFieldErrors({});
    void refreshHistory();
  }

  useEffect(() => {
    getPlantOptions()
      .then(setPlants)
      .catch((reason: unknown) => setError(reason instanceof Error ? reason.message : 'Tidak dapat memuat daftar plant.'))
      .finally(() => setPlantsLoading(false));
    getMachines({ page: 1, per_page: 100 }).then(setMachineDirectory).catch(() => setMachineDirectory([]));
  }, []);

  useEffect(() => {
    if (!reportOpen || qrPrefill || !authUser || authUser.role === 'admin' || plantId) return;
    const defaultPlant = plants.find((plant) => String(plant.id) === String(authUser.plant_id)) ?? plants[0];
    if (defaultPlant) setPlantId(String(defaultPlant.id));
  }, [authUser?.plant_id, authUser?.role, plantId, plants, qrPrefill, reportOpen]);

  useEffect(() => { void refreshHistory(); }, []);

  useEffect(() => {
    const openReport = (event: Event) => {
      const detail = (event as CustomEvent<QrPrefill | undefined>).detail;
      setReportOpen(true);
      setError('');
      setFormStep(0);
      setFieldErrors({});
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
  function collectValidationErrors(): IncidentFieldErrors {
    const errors: IncidentFieldErrors = {};
    if (!plantId) errors.plant = 'Pilih plant terlebih dahulu.';
    if (!machineId) errors.machine = 'Pilih mesin terlebih dahulu.';
    if (!problemType) errors.problemType = 'Pilih jenis masalah.';
    if (description.trim().length < 10) errors.description = 'Jelaskan minimal 10 karakter.';
    if (!attachmentFiles.length) errors.attachments = 'Lampirkan minimal satu foto atau video.';
    if (!resolution) errors.resolution = 'Pilih salah satu tindakan awal.';
    if (resolution === 'resolved' && actionTaken.trim().length < 5) errors.actionTaken = 'Jelaskan tindakan minimal 5 karakter.';
    if (resolution === 'resolved' && result.trim().length < 5) errors.result = 'Jelaskan hasil minimal 5 karakter.';
    return errors;
  }

  function errorsForStep(step: number, allErrors = collectValidationErrors()): IncidentFieldErrors {
    const fields: IncidentField[][] = [
      ['plant', 'machine'],
      ['problemType', 'description', 'attachments'],
      ['resolution', 'actionTaken', 'result'],
      [],
    ];
    return Object.fromEntries(fields[step].filter((field) => allErrors[field]).map((field) => [field, allErrors[field]])) as IncidentFieldErrors;
  }

  function clearFieldError(field: IncidentField) {
    setError('');
    setFieldErrors((current) => {
      const next = { ...current };
      delete next[field];
      return next;
    });
  }

  function openReportForm() {
    const defaultPlant = authUser && authUser.role !== 'admin'
      ? plants.find((plant) => String(plant.id) === String(authUser.plant_id)) ?? plants[0]
      : undefined;
    setReportOpen(true);
    setQrPrefill(null);
    setPlantId(defaultPlant ? String(defaultPlant.id) : '');
    setMachineId('');
    setFormStep(0);
    setFieldErrors({});
    setError('');
  }

  function continueReport() {
    const errors = errorsForStep(formStep);
    setFieldErrors(errors);
    if (Object.keys(errors).length) {
      const field = Object.keys(errors)[0] as IncidentField;
      const controlIds: Record<IncidentField, string> = { plant: 'incident-plant', machine: 'incident-machine', problemType: 'incident-problem-type', description: 'incident-description', attachments: 'incident-attachments', resolution: 'incident-resolution', actionTaken: 'incident-action-taken', result: 'incident-result' };
      window.requestAnimationFrame(() => {
        reportBodyRef.current?.scrollTo({ top: 0, behavior: 'smooth' });
        document.getElementById(controlIds[field])?.focus({ preventScroll: true });
      });
      return;
    }
    setError('');
    setFormStep((current) => Math.min(reportSteps.length - 1, current + 1));
    reportBodyRef.current?.scrollTo({ top: 0, behavior: 'smooth' });
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
    const validationErrors = collectValidationErrors();
    if (Object.keys(validationErrors).length) {
      setFieldErrors(validationErrors);
      const firstInvalidStep = validationErrors.plant || validationErrors.machine ? 0 : validationErrors.problemType || validationErrors.description || validationErrors.attachments ? 1 : 2;
      setFormStep(firstInvalidStep);
      setError('');
      reportBodyRef.current?.scrollTo({ top: 0, behavior: 'smooth' });
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

  const validationError = Object.keys(fieldErrors).length ? 'Periksa bagian yang ditandai sebelum melanjutkan.' : '';
  const selectedMachine: Machine | undefined = qrPrefill
    ? { ...qrPrefill.machine, section: qrPrefill.machine.section ?? undefined, plant_id: qrPrefill.plant.id, is_active: true }
    : machines.find((machine) => String(machine.id) === machineId);

  return <>
  {successOpen && <div className="incident-modal-backdrop incident-success-backdrop" role="presentation"><section className="incident-success-modal" role="alertdialog" aria-modal="true" aria-labelledby="incident-success-title"><CircleCheck className="incident-success-icon" aria-hidden="true" /><h2 id="incident-success-title">Laporan berhasil dikirim</h2>{successTicketNumber ? <><p>Laporan sudah tersimpan dan diteruskan ke tim Maintenance. Simpan nomor tiket ini untuk melihat perkembangannya.</p><div className="ticket-success-number"><span>Nomor Tiket Maintenance</span><strong>{successTicketNumber}</strong></div></> : <p>Laporan sudah tercatat sebagai selesai. Tidak ada tiket Maintenance yang perlu ditindaklanjuti.</p>}<button className="primary-button" type="button" onClick={acknowledgeSuccess}>Kembali ke riwayat</button></section></div>}
  {!plantsLoading && plants.length === 0 && !error && <EmptyState title="Plant belum tersedia untuk akun ini" description="Minta Admin menetapkan akses Plant di Manajemen Pengguna. Riwayat insiden tetap dapat ditinjau." />}
  {reportOpen && <div className="incident-modal-backdrop incident-form-backdrop" role="dialog" aria-modal="true" aria-labelledby="incident-form-title"><section className="incident-form-modal"><div className="incident-form-modal__header"><div><p className="eyebrow">Laporkan masalah pada mesin.</p><h2 id="incident-form-title">Buat Laporan Insiden</h2></div><button className="icon-button" type="button" onClick={() => setReportOpen(false)} aria-label="Tutup form insiden"><X aria-hidden="true" /></button></div><div className="incident-form-modal__body" ref={reportBodyRef}><div className="report-layout">
    <div className="report-main">
      {validationError && <p className="incident-validation-summary" role="alert" aria-live="assertive">{validationError}</p>}
      {error && <ErrorState title="Laporan belum tersimpan" description="Periksa kembali data laporan, lalu coba lagi." onRetry={() => void submitReport()} />}
      <nav className="incident-wizard-steps" aria-label="Tahapan laporan">
        <p>Langkah {formStep + 1} dari {reportSteps.length}</p>
        <div>{reportSteps.map((label, index) => <button type="button" key={label} className={index === formStep ? 'is-current' : index < formStep ? 'is-complete' : ''} aria-current={index === formStep ? 'step' : undefined} disabled={index > formStep} onClick={() => { setFormStep(index); setFieldErrors({}); setError(''); reportBodyRef.current?.scrollTo({ top: 0, behavior: 'smooth' }); }}><span>{index < formStep ? '✓' : index + 1}</span>{label}</button>)}</div>
      </nav>
      <p className="incident-required-note"><b>*</b> Wajib diisi</p>
      <section className="form-card incident-form-section" hidden={formStep !== 0}>
        <div className="incident-section-heading"><span className="incident-section-icon"><Factory aria-hidden="true" /></span><div><p className="eyebrow">Langkah 1</p><h2>Pilih lokasi dan mesin</h2><p>Pilih plant dan mesin yang mengalami masalah.</p></div></div>
        <div className="form-grid-two incident-location-grid">
          <label className="form-field"><span className="incident-field-label"><Factory aria-hidden="true" />Plant <b className="incident-required-mark">*</b></span>{qrPrefill ? <input id="incident-plant" value={`${qrPrefill.plant.code} - ${qrPrefill.plant.name}`} readOnly aria-readonly="true" /> : plantsLoading ? <Skeleton className="incident-select-skeleton" /> : <select id="incident-plant" aria-invalid={Boolean(fieldErrors.plant)} aria-describedby={fieldErrors.plant ? 'incident-plant-error' : undefined} value={plantId} onChange={(event) => { setPlantId(event.target.value); clearFieldError('plant'); clearFieldError('machine'); }} disabled={submitting}><option value="">Pilih plant</option>{plants.map((plant) => <option key={plant.id} value={plant.id}>{plant.code} - {plant.name}</option>)}</select>}{fieldErrors.plant && <small className="incident-field-error" id="incident-plant-error" role="alert">{fieldErrors.plant}</small>}</label>
          <label className="form-field"><span className="incident-field-label"><Cog aria-hidden="true" />Mesin <b className="incident-required-mark">*</b></span>{qrPrefill ? <input id="incident-machine" value={`${qrPrefill.machine.code} - ${qrPrefill.machine.name}`} readOnly aria-readonly="true" /> : machinesLoading ? <Skeleton className="incident-select-skeleton" /> : <select id="incident-machine" aria-invalid={Boolean(fieldErrors.machine)} aria-describedby={fieldErrors.machine ? 'incident-machine-error' : undefined} value={machineId} onChange={(event) => { setMachineId(event.target.value); clearFieldError('machine'); }} disabled={!plantId || submitting}><option value="">{!plantId ? 'Pilih plant terlebih dahulu' : 'Pilih mesin'}</option>{machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.code} - {machine.name}</option>)}</select>}{fieldErrors.machine && <small className="incident-field-error" id="incident-machine-error" role="alert">{fieldErrors.machine}</small>}</label>
        </div>
        {selectedPlant && <p className="form-helper">{qrPrefill ? 'Mesin dan Plant ditentukan dari QR Code.' : `Menampilkan ${machines.length} mesin pada ${selectedPlant.name}.`}</p>}
        {plantId && !machinesLoading && !machines.length && <p className="form-inline-error">Tidak ada mesin aktif pada plant ini.</p>}
      </section>
      <section className="form-card incident-form-section" hidden={formStep !== 1}>
        <div className="incident-section-heading"><span className="incident-section-icon"><CircleAlert aria-hidden="true" /></span><div><p className="eyebrow">Langkah 2</p><h2>Apa masalahnya?</h2><p>Pilih jenis masalah dan jelaskan kondisi yang terjadi.</p></div></div>
        <div className="form-field"><span className="incident-field-label"><ClipboardCheck aria-hidden="true" />Jenis masalah <b className="incident-required-mark">*</b></span><div id="incident-problem-type" className="choice-grid incident-choice-grid" role="group" aria-label="Jenis masalah" tabIndex={-1}>{problemTypes.map((type) => { const Icon = type.icon; return <button type="button" className={`choice-button incident-choice-card ${problemType === type.value ? 'choice-button--selected' : ''}`} key={type.value} onClick={() => { setProblemType(type.value); clearFieldError('problemType'); }} disabled={submitting}><Icon aria-hidden="true" /><span><strong>{type.label}</strong><small>{type.detail}</small></span></button>; })}</div>{fieldErrors.problemType && <small className="incident-field-error" role="alert">{fieldErrors.problemType}</small>}</div>
        <label className="form-field incident-description-field"><span className="incident-field-label">Kondisi sebelum perbaikan dan lokasi kerusakan <b className="incident-required-mark">*</b></span><textarea id="incident-description" aria-invalid={Boolean(fieldErrors.description)} aria-describedby={fieldErrors.description ? 'incident-description-error' : 'incident-description-helper'} required value={description} onChange={(event) => { setDescription(event.target.value); clearFieldError('description'); }} rows={3} placeholder="Contoh: bagian motor sisi kiri bergetar dan mengeluarkan suara keras..." disabled={submitting} /><span className="form-helper" id="incident-description-helper">Sebutkan bagian mesin yang rusak dan kondisi yang terlihat sebelum diperbaiki. Minimal 10 karakter.</span>{fieldErrors.description && <span className="incident-field-error" id="incident-description-error" role="alert">{fieldErrors.description}</span>}</label>
        <div className="form-field"><span className="incident-field-label">Foto atau video kondisi mesin <b className="incident-required-mark">*</b></span><span className="form-helper">Lampirkan minimal satu bukti. Foto: JPG, PNG, WEBP. Video: MP4, MOV, WEBM. Maksimal 18 MB total.</span><div id="incident-attachments" tabIndex={-1}><MediaAttachmentPicker files={attachmentFiles} onChange={(files) => { setAttachmentFiles(files); if (files.length) clearFieldError('attachments'); }} disabled={submitting} /></div>{fieldErrors.attachments && <small className="incident-field-error" role="alert">{fieldErrors.attachments}</small>}</div>
      </section>
      <section className="form-card incident-form-section" hidden={formStep !== 2}>
        <div className="incident-section-heading"><span className="incident-section-icon"><Wrench aria-hidden="true" /></span><div><p className="eyebrow">Langkah 3</p><h2>Tindakan awal</h2><p>Apakah masalah dapat ditangani Operator?</p></div></div>
        <div id="incident-resolution" className="resolution-grid incident-resolution-grid" role="group" aria-label="Tindakan awal" tabIndex={-1}><button type="button" className={`resolution-button incident-resolution-card ${resolution === 'resolved' ? 'resolution-button--selected' : ''}`} onClick={() => { setResolution('resolved'); clearFieldError('resolution'); }} disabled={submitting}><Check aria-hidden="true" /><span><strong>Dapat diselesaikan Operator</strong><small>Operator dapat menangani masalah ini.</small></span></button><button type="button" className={`resolution-button incident-resolution-card ${resolution === 'cannot-resolve' ? 'resolution-button--selected' : ''}`} onClick={() => { setResolution('cannot-resolve'); clearFieldError('resolution'); clearFieldError('actionTaken'); clearFieldError('result'); }} disabled={submitting}><Wrench aria-hidden="true" /><span><strong>Tidak dapat diselesaikan</strong><small>Masalah akan diteruskan ke Maintenance.</small></span></button></div>{fieldErrors.resolution && <small className="incident-field-error" role="alert">{fieldErrors.resolution}</small>}
        {resolution === 'resolved' && <div className="follow-up-fields"><label className="form-field">Tindakan yang dilakukan <b className="incident-required-mark">*</b><textarea id="incident-action-taken" aria-invalid={Boolean(fieldErrors.actionTaken)} value={actionTaken} onChange={(event) => { setActionTaken(event.target.value); clearFieldError('actionTaken'); }} rows={3} placeholder="Contoh: membersihkan sensor dan mengencangkan kabel." disabled={submitting} />{fieldErrors.actionTaken && <small className="incident-field-error" role="alert">{fieldErrors.actionTaken}</small>}</label><label className="form-field">Hasil tindakan <b className="incident-required-mark">*</b><textarea id="incident-result" aria-invalid={Boolean(fieldErrors.result)} value={result} onChange={(event) => { setResult(event.target.value); clearFieldError('result'); }} rows={3} placeholder="Contoh: mesin kembali berjalan normal." disabled={submitting} />{fieldErrors.result && <small className="incident-field-error" role="alert">{fieldErrors.result}</small>}</label></div>}
      </section>
      {formStep === 3 && <section className="form-card incident-form-section incident-review-section"><div className="incident-section-heading"><span className="incident-section-icon"><ClipboardCheck aria-hidden="true" /></span><div><p className="eyebrow">Langkah 4</p><h2>Periksa laporan</h2><p>Pastikan informasi sudah benar sebelum dikirim.</p></div></div><dl className="incident-review-list"><div><dt>Plant dan mesin</dt><dd>{selectedPlant?.name ?? '—'} · {selectedMachine?.name ?? '—'} <button type="button" onClick={() => { setFormStep(0); reportBodyRef.current?.scrollTo({ top: 0, behavior: 'smooth' }); }}>Ubah</button></dd></div><div><dt>Jenis masalah</dt><dd>{problemTypes.find((type) => type.value === problemType)?.label ?? '—'} <button type="button" onClick={() => { setFormStep(1); reportBodyRef.current?.scrollTo({ top: 0, behavior: 'smooth' }); }}>Ubah</button></dd></div><div><dt>Kondisi mesin</dt><dd>{description || '—'}</dd></div><div><dt>Lampiran</dt><dd>{attachmentFiles.length ? `${attachmentFiles.length} file: ${attachmentFiles.map((file) => file.name).join(', ')}` : 'Belum ada lampiran'}</dd></div><div><dt>Tindakan awal</dt><dd>{resolution === 'resolved' ? 'Dapat diselesaikan Operator' : resolution === 'cannot-resolve' ? 'Tidak dapat diselesaikan' : '—'} <button type="button" onClick={() => { setFormStep(2); reportBodyRef.current?.scrollTo({ top: 0, behavior: 'smooth' }); }}>Ubah</button></dd></div>{resolution === 'resolved' && <><div><dt>Tindakan yang dilakukan</dt><dd>{actionTaken || '—'}</dd></div><div><dt>Hasil tindakan</dt><dd>{result || '—'}</dd></div></>}</dl></section>}
    </div>
    <aside className="report-aside"><p className="eyebrow">Ringkasan</p><h2>{selectedPlant?.name ?? 'Pilih plant'}</h2><p>{selectedMachine?.name ?? 'Pilih mesin untuk melanjutkan laporan.'}</p></aside>
  </div></div><div className="incident-form-modal__footer"><button className="secondary-action" type="button" onClick={() => setReportOpen(false)} disabled={submitting}>Batal</button>{formStep > 0 && <button className="secondary-action" type="button" onClick={() => { setFormStep((current) => Math.max(0, current - 1)); setFieldErrors({}); setError(''); reportBodyRef.current?.scrollTo({ top: 0, behavior: 'smooth' }); }} disabled={submitting}>Kembali</button>}{formStep < reportSteps.length - 1 ? <button className="primary-button" type="button" onClick={continueReport} disabled={submitting || (formStep === 0 && machinesLoading)}>Lanjut</button> : <button className="primary-button" type="button" onClick={() => void submitReport()} disabled={submitting}><ClipboardCheck aria-hidden="true" />{submitting ? attachmentFiles.length ? 'Mengunggah...' : 'Mengirim...' : 'Kirim Laporan'}</button>}</div></section></div>}
  <section className="incident-history">
    <div className="incident-history-heading">
      <div><p className="eyebrow">Catatan tersimpan</p><h2>Riwayat Insiden</h2><p>Daftar laporan masalah.</p></div>
      <div className="incident-history-actions"><button className="primary-button incident-open-button" type="button" onClick={openReportForm}><Plus aria-hidden="true" /><span className="incident-open-button__desktop-label">Buat Laporan</span><span className="incident-open-button__mobile-label">Buat Laporan Insiden</span></button><button className="secondary-action incident-refresh-button" type="button" onClick={() => void refreshHistory()} disabled={historyLoading} aria-label="Muat ulang" title="Muat ulang"><RefreshCw aria-hidden="true" /><span className="incident-refresh-button__desktop-label">Muat ulang</span></button></div>
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
