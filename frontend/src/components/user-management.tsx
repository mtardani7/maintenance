'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { CircleOff, Pencil, Plus, RotateCcw, Search, X } from 'lucide-react';
import { adminUserApiMessage, createManagedUser, getManagedUsers, updateManagedUser, type ManagedUser, type UserInput } from '@/lib/admin-user-api';
import { roles } from '@/lib/roles';
import type { Role } from '@/lib/types';
import { DataPagination, EmptyState, ErrorState, MaintenanceFilterBar, MaintenanceTable, PaginationSkeleton, SectionHeading, TableSkeleton } from './ui';
import { useAuthUser } from './auth-boundary';

const blankForm = { name: '', email: '', phone: '', nik: '', role: 'operator' as Role, is_active: true, password: '', password_confirmation: '' };

export function UserManagement() {
  const authUser = useAuthUser();
  const [users, setUsers] = useState<ManagedUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [pageInfo, setPageInfo] = useState({ total: 0, last: 1 });
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<ManagedUser | null>(null);
  const [deactivateTarget, setDeactivateTarget] = useState<ManagedUser | null>(null);
  const [form, setForm] = useState(blankForm);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState('');

  async function refresh(targetPage = page) {
    setLoading(true);
    setError('');
    try {
      const result = await getManagedUsers({ search: search.trim(), role, status, page: targetPage, per_page: pageSize });
      setUsers(result.data);
      setPageInfo({ total: result.total, last: result.last_page });
    } catch (reason) {
      setError(adminUserApiMessage(reason));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { void refresh(); }, [search, role, status, page, pageSize]);

  function openCreate() {
    setEditing(null); setForm(blankForm); setFormError(''); setDialogOpen(true);
  }

  function openEdit(user: ManagedUser) {
    setEditing(user);
    setForm({ name: user.name, email: user.email, phone: user.phone ?? '', nik: user.nik ?? '', role: user.role, is_active: user.is_active, password: '', password_confirmation: '' });
    setFormError(''); setDialogOpen(true);
  }

  function update(field: keyof typeof blankForm, value: string | boolean) {
    setForm((current) => ({ ...current, [field]: value }));
    setFormError('');
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editing && form.password !== form.password_confirmation) {
      setFormError('Konfirmasi kata sandi tidak cocok.'); return;
    }
    if (editing && form.password && form.password !== form.password_confirmation) {
      setFormError('Konfirmasi kata sandi tidak cocok.'); return;
    }
    setSaving(true); setFormError('');
    const payload: UserInput = {
      name: form.name.trim(), email: form.email.trim(), phone: form.phone.trim() || null,
      nik: form.nik.trim() || null, role: form.role, is_active: form.is_active,
      ...(form.password ? { password: form.password, password_confirmation: form.password_confirmation } : {}),
    };
    try {
      if (editing) await updateManagedUser(editing.id, payload);
      else await createManagedUser({ ...payload, password: form.password, password_confirmation: form.password_confirmation });
      setDialogOpen(false); await refresh();
    } catch (reason) {
      setFormError(adminUserApiMessage(reason));
    } finally { setSaving(false); }
  }

  async function deactivate() {
    if (!deactivateTarget) return;
    setSaving(true); setError('');
    try {
      await updateManagedUser(deactivateTarget.id, { name: deactivateTarget.name, email: deactivateTarget.email, phone: deactivateTarget.phone, nik: deactivateTarget.nik, role: deactivateTarget.role, is_active: false });
      setDeactivateTarget(null); await refresh();
    } catch (reason) { setError(adminUserApiMessage(reason)); }
    finally { setSaving(false); }
  }

  if (authUser?.role !== 'admin') {
    return <><SectionHeading eyebrow="Master Data" title="Manajemen Pengguna" description="Kelola akun dan data karyawan." /><ErrorState title="Akses Admin diperlukan" description="Hanya Admin yang dapat mengelola pengguna." /></>;
  }

  const start = pageInfo.total === 0 ? 0 : (page - 1) * pageSize + 1;
  const end = Math.min(page * pageSize, pageInfo.total);
  const hasFilters = Boolean(search.trim() || role || status);
  const roleLabel = (value: Role) => value === 'technician' ? 'Maintenance' : roles.find((item) => item.id === value)?.label ?? value;
  return <div className="machine-browser user-management">
    <SectionHeading eyebrow="Data aset" title="Manajemen Pengguna" description="Kelola akun dan data karyawan." action={<div className="dashboard-header-actions"><button className="primary-button" type="button" onClick={openCreate}><Plus aria-hidden="true" /> Tambah Pengguna</button></div>} />
    <section className="machine-registry user-management__registry">
      <div className="machine-registry-header"><div><h2>Daftar Pengguna</h2><p>{pageInfo.total} pengguna</p></div><MaintenanceFilterBar className={`machine-table-filters user-management__filters ${hasFilters ? 'has-filters' : ''}`} label="Filter pengguna">
        <label className="machine-search"><Search aria-hidden="true" /><input aria-label="Cari pengguna" placeholder="Cari pengguna" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} /></label>
        <label className="user-management__select"><span>Role</span><select aria-label="Filter role" value={role} onChange={(event) => { setRole(event.target.value); setPage(1); }}><option value="">Semua Role</option>{roles.map((item) => <option value={item.id} key={item.id}>{roleLabel(item.id)}</option>)}</select></label>
        <label className="user-management__select"><span>Status</span><select aria-label="Filter status" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}><option value="">Semua Status</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select></label>
        <button type="button" className="secondary-action" onClick={() => { setSearch(''); setRole(''); setStatus(''); setPage(1); }}><RotateCcw aria-hidden="true" /><span className="user-management__reset-desktop">Reset</span><span className="user-management__reset-mobile">Reset Filter</span></button>
      </MaintenanceFilterBar></div>
      {error ? <div className="user-management__state"><ErrorState title="Gagal memuat pengguna" description="Data pengguna belum dapat dimuat. Silakan coba lagi." onRetry={() => void refresh()} /></div> : loading ? <><TableSkeleton className="machine-table-scroll" tableClassName="machine-data-table user-management__table" headers={['Nama', 'NIK', 'No. Telepon', 'Role', 'Status', 'Aksi']} rows={6} /><PaginationSkeleton className="machine-pagination" /></> : users.length === 0 ? <div className="user-management__state"><EmptyState title="Belum ada pengguna" description="Belum ada pengguna yang terdaftar." action={<button className="primary-button" type="button" onClick={openCreate}><Plus aria-hidden="true" /> Tambah Pengguna</button>} /></div> : <><MaintenanceTable containerClassName="machine-table-scroll" className="machine-data-table user-management__table" label="Daftar pengguna"><thead><tr><th>Nama</th><th>NIK</th><th>No. Telepon</th><th>Role</th><th>Status</th><th className="machine-actions-heading">Aksi</th></tr></thead><tbody>{users.map((item) => <tr key={item.id}><td><strong>{item.name}</strong><small>{item.email}</small></td><td>{item.nik || '—'}</td><td>{item.phone || '—'}</td><td><span className="user-role-badge">{roleLabel(item.role)}</span></td><td><span className={`user-status-badge ${item.is_active ? 'is-active' : 'is-inactive'}`}>{item.is_active ? 'Aktif' : 'Nonaktif'}</span></td><td><div className="machine-actions user-management__actions"><button type="button" className="user-management__edit" onClick={() => openEdit(item)} aria-label={`Ubah ${item.name}`} title="Ubah"><Pencil aria-hidden="true" /></button>{item.is_active && <button type="button" className="user-management__deactivate" onClick={() => setDeactivateTarget(item)} aria-label={`Nonaktifkan ${item.name}`} title="Nonaktifkan"><CircleOff aria-hidden="true" /></button>}</div></td></tr>)}</tbody></MaintenanceTable><DataPagination currentPage={page} totalPages={pageInfo.last} pageSize={pageSize} pageSizeOptions={[10, 25, 50]} onPageSizeChange={(value) => { setPageSize(value); setPage(1); }} onPageChange={setPage} summary={<>Menampilkan {start}–{end} dari {pageInfo.total} pengguna</>} mobileSummary={<>{start}–{end} dari {pageInfo.total}</>} /></>}
    </section>

    {dialogOpen && <div className="machine-modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setDialogOpen(false); }}><form className="form-card machine-modal user-management__modal" role="dialog" aria-modal="true" aria-labelledby="user-modal-title" onSubmit={submit}>
      <div className="machine-request-header"><div><h2 id="user-modal-title">{editing ? 'Ubah Pengguna' : 'Tambah Pengguna'}</h2><p className="machine-modal-description">Isi informasi akun dan akses pengguna.</p></div><button type="button" className="icon-button" onClick={() => setDialogOpen(false)} aria-label="Tutup dialog"><X aria-hidden="true" /></button></div>
      <div className="user-management__form-grid">
        <label className="form-field">Nama<input required maxLength={255} value={form.name} onChange={(event) => update('name', event.target.value)} /></label>
        <label className="form-field">Email<input required type="email" maxLength={255} value={form.email} onChange={(event) => update('email', event.target.value)} /></label>
        <label className="form-field">No. Telepon<input maxLength={50} value={form.phone} onChange={(event) => update('phone', event.target.value)} /></label>
        <label className="form-field">NIK<input maxLength={100} value={form.nik} onChange={(event) => update('nik', event.target.value)} /></label>
        <label className="form-field">Role<select value={form.role} onChange={(event) => update('role', event.target.value)}>{roles.map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}</select></label>
        <label className="form-field">Status<select value={form.is_active ? 'active' : 'inactive'} onChange={(event) => update('is_active', event.target.value === 'active')}><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select></label>
        <label className="form-field">{editing ? 'Kata sandi baru (opsional)' : 'Kata sandi'}<input type="password" autoComplete="new-password" minLength={8} required={!editing} value={form.password} onChange={(event) => update('password', event.target.value)} /></label>
        <label className="form-field">Konfirmasi kata sandi<input type="password" autoComplete="new-password" minLength={8} required={!editing && Boolean(form.password)} value={form.password_confirmation} onChange={(event) => update('password_confirmation', event.target.value)} /></label>
      </div>
      {formError && <p className="form-error" role="alert">{formError}</p>}
      <div className="machine-modal-actions"><button className="secondary-action" type="button" onClick={() => setDialogOpen(false)}>Batal</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Menyimpan...' : 'Simpan Pengguna'}</button></div>
    </form></div>}

    {deactivateTarget && <div className="machine-modal-backdrop" role="presentation"><section className="form-card machine-modal user-management__confirm" role="dialog" aria-modal="true" aria-labelledby="deactivate-user-title"><div className="machine-request-header"><div><h2 id="deactivate-user-title">Nonaktifkan Pengguna</h2><p className="machine-modal-description">{deactivateTarget.name} tidak dapat masuk setelah dinonaktifkan. Riwayat pengguna tetap tersimpan.</p></div></div><div className="machine-modal-actions"><button type="button" className="secondary-action" disabled={saving} onClick={() => setDeactivateTarget(null)}>Batal</button><button type="button" className="danger-button" disabled={saving} onClick={() => void deactivate()}>{saving ? 'Menyimpan...' : 'Nonaktifkan Pengguna'}</button></div></section></div>}
  </div>;
}
