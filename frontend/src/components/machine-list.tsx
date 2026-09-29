'use client';

import { CheckCircle2, ChevronLeft, ChevronRight, CircleOff, Pencil, Plus, RotateCcw, Search, Trash2, X } from 'lucide-react';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { deleteMachine, getMachinePage, getPlants, getPlantOptions, requestMachine, updateMachine, type PlantOption } from '@/lib/maintenance-api';
import type { Machine } from '@/lib/maintenance-types';
import { EmptyState, ErrorState, PaginationSkeleton, SectionHeading, TableSkeleton } from './ui';

type MachineForm = { plant_id: string; code: string; name: string; section: string; is_active: boolean };
type FormErrors = Partial<Record<keyof MachineForm, string>>;
const emptyForm: MachineForm = { plant_id: '', code: '', name: '', section: '', is_active: true };

export function MachineList() {
  const [machines, setMachines] = useState<Machine[]>([]);
  const [plants, setPlants] = useState<PlantOption[]>([]);
  const [filterPlants, setFilterPlants] = useState<PlantOption[]>([]);
  const [page, setPage] = useState(1);
  const [pageSize] = useState(20);
  const [pageInfo, setPageInfo] = useState({ current: 1, last: 1, total: 0 });
  const [search, setSearch] = useState('');
  const [plant, setPlant] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Machine | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Machine | null>(null);
  const [form, setForm] = useState<MachineForm>(emptyForm);
  const [formErrors, setFormErrors] = useState<FormErrors>({});
  const [saving, setSaving] = useState(false);
  const [deactivating, setDeactivating] = useState(false);
  const [successMessage, setSuccessMessage] = useState('');

  async function refresh(targetPage = page) {
    setLoading(true); setError('');
    try {
      const result = await getMachinePage({ search: search.trim(), plant, status, page: targetPage, per_page: pageSize });
      setMachines(result.data); setPageInfo({ current: result.current_page, last: result.last_page, total: result.total }); setError('');
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Tidak dapat memuat mesin.'); }
    finally { setLoading(false); }
  }
  useEffect(() => {
    void getPlantOptions().then(setPlants).catch(() => setPlants([]));
    void getPlants({ per_page: 100 }).then((result) => setFilterPlants(result.data)).catch(() => setFilterPlants([]));
  }, []);
  useEffect(() => { void refresh(); }, [plant, search, status, page, pageSize]);
  function openCreate() { setEditing(null); setForm(emptyForm); setFormErrors({}); setFormOpen(true); }
  function openEdit(machine: Machine) { setEditing(machine); setForm({ plant_id: String(machine.plant_id), code: machine.code, name: machine.name, section: machine.section ?? '', is_active: machine.is_active }); setFormErrors({}); setFormOpen(true); }
  function validate(): FormErrors {
    const errors: FormErrors = {};
    if (!form.plant_id) errors.plant_id = 'Plant wajib dipilih.';
    if (!form.code.trim()) errors.code = 'Kode mesin wajib diisi.';
    if (!form.name.trim()) errors.name = 'Nama mesin wajib diisi.';
    return errors;
  }
  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const errors = validate(); setFormErrors(errors);
    if (Object.keys(errors).length) return;
    setSaving(true);
    try {
      const payload = { ...form, code: form.code.trim(), name: form.name.trim(), section: form.section.trim(), plant_id: Number(form.plant_id) };
      if (editing) await updateMachine(editing.id, payload); else await requestMachine(payload);
      const selectedPlant = plants.find((option) => String(option.id) === form.plant_id);
      const savedName = form.name.trim();
      setFormOpen(false); setPage(1); await refresh(1);
      setSuccessMessage(editing ? `${savedName} berhasil diperbarui.` : `${savedName} berhasil ditambahkan${selectedPlant ? ` ke ${selectedPlant.name}` : ''}.`);
    } catch (reason) {
      const message = reason instanceof Error ? reason.message : 'Tidak dapat menyimpan mesin.';
      const lower = message.toLowerCase();
      if (lower.includes('plant')) setFormErrors({ plant_id: message });
      else if (lower.includes('code') || lower.includes('kode') || lower.includes('duplicate') || lower.includes('unique')) setFormErrors({ code: message });
      else if (lower.includes('name') || lower.includes('nama')) setFormErrors({ name: message });
      else setError(message);
    }
    finally { setSaving(false); }
  }
  async function confirmRemove() {
    if (!deleteTarget) return;
    setDeactivating(true);
    try { await deleteMachine(deleteTarget.id); const name = deleteTarget.name; setDeleteTarget(null); await refresh(); setSuccessMessage(`${name} berhasil dinonaktifkan.`); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Tidak dapat menonaktifkan mesin.'); }
    finally { setDeactivating(false); }
  }

  const selectedPlantName = (machine: Machine) => {
    const relation: unknown = machine.plant;
    if (typeof relation === 'string' && relation.trim()) return relation;
    if (relation && typeof relation === 'object') {
      const relatedPlant = relation as { name?: unknown; code?: unknown };
      if (typeof relatedPlant.name === 'string' && relatedPlant.name.trim()) return relatedPlant.name;
      if (typeof relatedPlant.code === 'string' && relatedPlant.code.trim()) return relatedPlant.code;
    }
    return filterPlants.find((option) => String(option.id) === String(machine.plant_id))?.name || plants.find((option) => String(option.id) === String(machine.plant_id))?.name || `Plant ${machine.plant_id}`;
  };
  return <div className="machine-browser">
    <SectionHeading eyebrow="Data aset" title="Master Mesin" description="Kumpulan mesin yang digunakan di setiap Plant." action={<div className="dashboard-header-actions"><button id="create-machine" type="button" className="primary-button" onClick={openCreate}><Plus aria-hidden="true" /> Tambah Mesin</button></div>} />
    {formOpen && <div className="machine-modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setFormOpen(false); }}><form className="form-card machine-modal" role="dialog" aria-modal="true" aria-labelledby="machine-form-title" onSubmit={submit}>
      <div className="machine-request-header"><div><h2 id="machine-form-title">{editing ? 'Edit Mesin' : 'Tambah Mesin'}</h2><p className="machine-modal-description">{editing ? 'Perbarui informasi mesin.' : 'Tambahkan mesin yang digunakan di Plant.'}</p></div><button type="button" className="icon-button" onClick={() => setFormOpen(false)} aria-label="Tutup dialog"><X aria-hidden="true" /></button></div>
      <div className="machine-request-grid">
        <label className="form-field">Plant<select required aria-invalid={Boolean(formErrors.plant_id)} aria-describedby={formErrors.plant_id ? 'machine-plant-error' : undefined} value={form.plant_id} onChange={(event) => { setForm({ ...form, plant_id: event.target.value }); setFormErrors({ ...formErrors, plant_id: undefined }); }}><option value="">Pilih Plant</option>{plants.map((option) => <option key={option.id} value={option.id}>{option.code} — {option.name}</option>)}</select>{formErrors.plant_id && <small className="machine-field-error" id="machine-plant-error">{formErrors.plant_id}</small>}</label>
        <label className="form-field">Kode Mesin<input required placeholder="Contoh: MCH-001" aria-invalid={Boolean(formErrors.code)} aria-describedby={formErrors.code ? 'machine-code-error' : undefined} value={form.code} onChange={(event) => { setForm({ ...form, code: event.target.value }); setFormErrors({ ...formErrors, code: undefined }); }} />{formErrors.code && <small className="machine-field-error" id="machine-code-error">{formErrors.code}</small>}</label>
        <label className="form-field">Nama Mesin<input required placeholder="Masukkan nama mesin" aria-invalid={Boolean(formErrors.name)} aria-describedby={formErrors.name ? 'machine-name-error' : undefined} value={form.name} onChange={(event) => { setForm({ ...form, name: event.target.value }); setFormErrors({ ...formErrors, name: undefined }); }} />{formErrors.name && <small className="machine-field-error" id="machine-name-error">{formErrors.name}</small>}</label>
        <label className="form-field">Section (opsional)<input placeholder="Masukkan section" value={form.section} onChange={(event) => setForm({ ...form, section: event.target.value })} /></label>
        <fieldset className="machine-status-field"><legend>Status</legend><label><input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} /><span className="machine-status-dot" />{form.is_active ? 'Aktif' : 'Nonaktif'}</label></fieldset>
      </div>
      <div className="machine-modal-actions"><button className="secondary-action" type="button" onClick={() => setFormOpen(false)} disabled={saving}>Batal</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Menyimpan...' : editing ? 'Simpan Perubahan' : 'Simpan Mesin'}</button></div>
    </form></div>}
    {deleteTarget && <div className="machine-modal-backdrop" role="presentation"><div className="machine-modal machine-delete-modal" role="dialog" aria-modal="true" aria-labelledby="machine-delete-title"><div className="machine-request-header"><div><h2 id="machine-delete-title">Nonaktifkan mesin ini?</h2></div><button type="button" className="icon-button" onClick={() => setDeleteTarget(null)} aria-label="Tutup dialog"><X aria-hidden="true" /></button></div><p>Mesin yang sudah digunakan dalam riwayat maintenance tetap akan tersimpan.</p><div className="machine-modal-actions"><button className="secondary-action" type="button" onClick={() => setDeleteTarget(null)} disabled={deactivating}>Batal</button><button className="danger-button" type="button" onClick={() => void confirmRemove()} disabled={deactivating}>{deactivating ? 'Menonaktifkan...' : 'Nonaktifkan'}</button></div></div></div>}
    {successMessage && <div className="machine-modal-backdrop" role="presentation"><div className="machine-modal machine-success-modal" role="alertdialog" aria-modal="true" aria-labelledby="machine-success-title"><span className="machine-success-icon"><CheckCircle2 aria-hidden="true" /></span><h2 id="machine-success-title">{successMessage.includes('ditambahkan') ? 'Mesin berhasil ditambahkan' : successMessage.includes('diperbarui') ? 'Perubahan berhasil disimpan' : 'Mesin berhasil dinonaktifkan'}</h2><p>{successMessage}</p><div className="machine-modal-actions"><button className="primary-button" type="button" onClick={() => setSuccessMessage('')}>OK</button></div></div></div>}
    <section className="machine-registry"><div className="machine-registry-header"><div><h2>Daftar Mesin</h2><p>{pageInfo.total} mesin</p></div><div className="machine-table-filters"><label className="machine-search"><Search aria-hidden="true" /><input aria-label="Cari mesin" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Cari mesin" /></label><select aria-label="Plant" value={plant} onChange={(event) => { setPlant(event.target.value); setPage(1); }}><option value="">Semua Plant</option>{filterPlants.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select><select aria-label="Status" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}><option value="">Semua status</option><option value="running">Aktif</option><option value="offline">Nonaktif</option></select><button className="secondary-action" type="button" onClick={() => { setSearch(''); setPlant(''); setStatus(''); setPage(1); }}><RotateCcw aria-hidden="true" />Reset filter</button></div></div>
      {error && <div className="machine-list-error"><ErrorState title="Mesin tidak tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => void refresh()} /></div>}
      {loading ? <><TableSkeleton className="machine-table-scroll" tableClassName="machine-data-table" headers={["Kode", "Nama Mesin", "Plant", "Section", "Status", "Aksi"]} rows={6} /><PaginationSkeleton className="machine-pagination" /></> : !error && machines.length === 0 ? <EmptyState title="Belum ada mesin" description="Tidak ada mesin yang sesuai dengan filter." /> : !error && <><div className="machine-table-scroll"><table className="machine-data-table"><thead><tr><th>Kode</th><th>Nama Mesin</th><th>Plant</th><th>Section</th><th>Status</th><th className="machine-actions-heading">Aksi</th></tr></thead><tbody>{machines.map((machine) => <tr key={machine.id}><td><Link href={`/machines/${machine.id}`}><strong>{machine.code}</strong></Link></td><td><Link href={`/machines/${machine.id}`}>{machine.name}</Link></td><td>{selectedPlantName(machine)}</td><td>{machine.section || '—'}</td><td><span className={`machine-status ${machine.is_active ? 'machine-status--active' : 'machine-status--inactive'}`}>{machine.is_active ? <CheckCircle2 aria-hidden="true" /> : <CircleOff aria-hidden="true" />}{machine.is_active ? 'Aktif' : 'Nonaktif'}</span></td><td><div className="machine-actions"><button type="button" onClick={() => openEdit(machine)} aria-label={`Edit ${machine.name}`} title="Edit"><Pencil aria-hidden="true" /></button><button type="button" onClick={() => setDeleteTarget(machine)} aria-label={`Nonaktifkan ${machine.name}`} title="Nonaktifkan"><Trash2 aria-hidden="true" /></button></div></td></tr>)}</tbody></table></div><div className="machine-pagination"><p>Halaman {pageInfo.current} dari {pageInfo.last}</p><div><button type="button" disabled={pageInfo.current <= 1} onClick={() => setPage((value) => value - 1)}><ChevronLeft aria-hidden="true" /> Sebelumnya</button><button type="button" disabled={pageInfo.current >= pageInfo.last} onClick={() => setPage((value) => value + 1)}>Berikutnya <ChevronRight aria-hidden="true" /></button></div></div></>}</section>
  </div>;
}
