'use client';

import { CheckCircle2, ChevronLeft, ChevronRight, CircleOff, Pencil, Plus, RotateCcw, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { deactivatePlant, createPlant, getPlants, updatePlant } from '@/lib/maintenance-api';
import { getCurrentUser } from '@/lib/auth';
import type { Plant } from '@/lib/maintenance-types';
import { EmptyState, ErrorState, LoadingState, PaginationSkeleton, SectionHeading, TableSkeleton } from './ui';

type FormState = { code: string; name: string; description: string; is_active: boolean };
const emptyForm: FormState = { code: '', name: '', description: '', is_active: true };

export function PlantList() {
  const [plants, setPlants] = useState<Plant[]>([]);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [pageInfo, setPageInfo] = useState({ current: 1, last: 1, total: 0 });
  const [role, setRole] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Plant | null>(null);
  const [target, setTarget] = useState<Plant | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [saving, setSaving] = useState(false);

  async function refresh() {
    setLoading(true);
    try { const result = await getPlants({ search, is_active: status, page, per_page: 20 }); setPlants(result.data); setPageInfo({ current: result.current_page, last: result.last_page, total: result.total }); setError(''); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Tidak dapat memuat Plant.'); }
    finally { setLoading(false); }
  }
  useEffect(() => { getCurrentUser().then((result) => setRole(result.status === 'authenticated' ? result.user.role ?? null : null)); }, []);
  useEffect(() => { if (role === 'admin') void refresh(); else if (role !== null) setLoading(false); }, [role, search, status, page]);
  function openCreate() { setEditing(null); setForm(emptyForm); setFormOpen(true); }
  function openEdit(plant: Plant) { setEditing(plant); setForm({ code: plant.code, name: plant.name, description: plant.description ?? '', is_active: plant.is_active }); setFormOpen(true); }
  async function submit(event: React.FormEvent<HTMLFormElement>) { event.preventDefault(); setSaving(true); setSuccess(''); try { if (editing) { await updatePlant(editing.id, form); setSuccess(`${form.name} berhasil diperbarui.`); } else { await createPlant(form); setSuccess(`${form.name} berhasil ditambahkan.`); } setFormOpen(false); setPage(1); await refresh(); } catch (reason) { setError(reason instanceof Error ? reason.message : 'Plant tidak dapat disimpan.'); } finally { setSaving(false); } }
  async function confirmDelete() { if (!target) return; try { const name = target.name; await deactivatePlant(target.id); setTarget(null); setSuccess(`${name} berhasil dinonaktifkan.`); await refresh(); } catch (reason) { setError(reason instanceof Error ? reason.message : 'Plant tidak dapat dinonaktifkan.'); } }

  if (role === null || loading && role === null) return <LoadingState label="Memeriksa akses Plant" />;
  if (role !== 'admin') return <ErrorState title="Akses Admin diperlukan" description="Master Plant hanya dapat dikelola oleh Admin." />;

  return <div className="plant-browser">
    <SectionHeading eyebrow="Data aset" title="Master Plant" description="Kelola lokasi kerja dan status Plant." action={<div className="dashboard-header-actions"><button className="primary-button" type="button" onClick={openCreate}><Plus aria-hidden="true" /> Tambah Plant</button></div>} />
    {success && <p className="form-success" role="status">{success}</p>}
    {formOpen && <div className="plant-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="plant-form-title"><form className="plant-modal" onSubmit={submit}><div className="plant-modal__header"><div><p className="eyebrow">Data Plant</p><h2 id="plant-form-title">{editing ? 'Ubah Plant' : 'Tambah Plant'}</h2><p className="machine-modal-description">Isi informasi Plant yang digunakan di sistem.</p></div><button className="icon-button" type="button" onClick={() => setFormOpen(false)} aria-label="Tutup form"><X aria-hidden="true" /></button></div><label className="form-field">Kode Plant<input required placeholder="Contoh: PLT-001" value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} /></label><label className="form-field">Nama Plant<input required placeholder="Masukkan nama Plant" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></label><label className="form-field">Deskripsi (opsional)<textarea rows={3} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></label><label className="plant-active-field"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} />Plant aktif</label><div className="plant-modal__actions"><button className="secondary-action" type="button" onClick={() => setFormOpen(false)}>Batal</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Menyimpan...' : editing ? 'Simpan Perubahan' : 'Simpan Plant'}</button></div></form></div>}
    {target && <div className="plant-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="plant-delete-title"><div className="plant-modal"><div className="plant-modal__header"><div><p className="eyebrow">Konfirmasi</p><h2 id="plant-delete-title">Nonaktifkan Plant ini?</h2></div><button className="icon-button" type="button" onClick={() => setTarget(null)} aria-label="Tutup konfirmasi"><X aria-hidden="true" /></button></div><p>Data mesin dan riwayat pada Plant ini akan tetap tersimpan.</p><div className="plant-modal__actions"><button className="secondary-action" type="button" onClick={() => setTarget(null)}>Batal</button><button className="danger-button" type="button" onClick={() => void confirmDelete()}>Nonaktifkan</button></div></div></div>}
    <section className="plant-registry"><div className="machine-registry-header"><div><h2>Daftar Plant</h2><p>{pageInfo.total} Plant</p></div><div className="machine-table-filters"><label className="machine-search"><Search aria-hidden="true" /><input aria-label="Cari Plant" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Cari Plant" /></label><select aria-label="Status" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}><option value="">Semua status</option><option value="true">Aktif</option><option value="false">Nonaktif</option></select><button className="secondary-action" type="button" onClick={() => { setSearch(''); setStatus(''); setPage(1); }}><RotateCcw aria-hidden="true" />Reset filter</button></div></div>{loading ? <><TableSkeleton className="plant-table-scroll" tableClassName="plant-table" headers={["Kode", "Nama Plant", "Deskripsi", "Status", "Aksi"]} rows={6} /><PaginationSkeleton className="plant-pagination" /></> : error ? <ErrorState title="Daftar Plant tidak tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => void refresh()} /> : plants.length === 0 ? <EmptyState title="Belum ada Plant" description="Tidak ada Plant yang sesuai. Ubah filter atau tambahkan Plant baru." /> : <><div className="plant-table-scroll"><table className="plant-table"><thead><tr><th>Kode</th><th>Nama Plant</th><th>Deskripsi</th><th>Status</th><th>Aksi</th></tr></thead><tbody>{plants.map((plant) => <tr key={plant.id}><td><strong>{plant.code}</strong></td><td>{plant.name}</td><td>{plant.description || '—'}</td><td><span className={`plant-status ${plant.is_active ? 'plant-status--active' : 'plant-status--inactive'}`}>{plant.is_active ? <CheckCircle2 aria-hidden="true" /> : <CircleOff aria-hidden="true" />}{plant.is_active ? 'Aktif' : 'Nonaktif'}</span></td><td><button className="plant-icon-button" type="button" onClick={() => openEdit(plant)} aria-label={`Ubah ${plant.name}`} title="Ubah"><Pencil aria-hidden="true" /></button><button className="plant-icon-button" type="button" onClick={() => setTarget(plant)} aria-label={`Nonaktifkan ${plant.name}`} title="Nonaktifkan"><CircleOff aria-hidden="true" /></button></td></tr>)}</tbody></table></div><div className="plant-pagination"><p>Halaman {pageInfo.current} dari {pageInfo.last}</p><div><button className="secondary-action" disabled={pageInfo.current <= 1} onClick={() => setPage(pageInfo.current - 1)}><ChevronLeft aria-hidden="true" /> Sebelumnya</button><button className="secondary-action" disabled={pageInfo.current >= pageInfo.last} onClick={() => setPage(pageInfo.current + 1)}>Berikutnya <ChevronRight aria-hidden="true" /></button></div></div></>}</section>
  </div>;
}
