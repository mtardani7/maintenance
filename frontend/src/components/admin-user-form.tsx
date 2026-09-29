'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { createUser, adminUserApiMessage } from '@/lib/admin-user-api';
import { getCurrentUser } from '@/lib/auth';
import { roles } from '@/lib/roles';
import type { Role } from '@/lib/types';
import { ErrorState, FormSkeleton } from './ui';

const accountRoles = roles;

export function AdminUserForm() {
  const [role, setRole] = useState<Role | null>(null);
  const [form, setForm] = useState({ name: '', email: '', role: 'operator' as Role, password: '', password_confirmation: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  useEffect(() => {
    getCurrentUser().then((result) => {
      if (result.status === 'authenticated') setRole(result.user.role ?? null);
      else setRole(null);
    });
  }, []);

  function update(field: keyof typeof form, value: string) {
    setForm((current) => ({ ...current, [field]: value }));
    setError('');
    setSuccess('');
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (form.password !== form.password_confirmation) {
      setError('Kata sandi tidak cocok.');
      return;
    }
    setBusy(true);
    setError('');
    setSuccess('');
    try {
      await createUser(form);
      setForm({ name: '', email: '', role: 'operator', password: '', password_confirmation: '' });
      setSuccess('Akun berhasil dibuat.');
    } catch (reason) {
      setError(adminUserApiMessage(reason));
    } finally {
      setBusy(false);
    }
  }

  if (role === null) return <section className="account-panel work-panel"><div className="work-panel__header"><div><p className="eyebrow">Manajemen pengguna</p><h2>Buat akun</h2></div><span>KHUSUS ADMIN</span></div><p className="account-panel__intro">Memeriksa akses Admin</p><FormSkeleton fields={5} /></section>;
  if (role !== 'admin') return <ErrorState title="Akses Admin diperlukan" description="Hanya pengguna Admin yang dapat membuat akun." />;

  return <section className="account-panel work-panel">
    <div className="work-panel__header"><div><p className="eyebrow">Manajemen pengguna</p><h2>Buat akun</h2></div><span>KHUSUS ADMIN</span></div>
    <p className="account-panel__intro">Buat akun untuk salah satu dari lima peran sistem.</p>
    <form className="account-form" onSubmit={submit}>
      <label className="form-field">Nama lengkap<input value={form.name} onChange={(event) => update('name', event.target.value)} autoComplete="name" required /></label>
      <label className="form-field">Email<input type="email" value={form.email} onChange={(event) => update('email', event.target.value)} autoComplete="email" required /></label>
      <label className="form-field">Peran<select value={form.role} onChange={(event) => update('role', event.target.value)}>{accountRoles.map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}</select></label>
      <label className="form-field">Kata sandi<input type="password" value={form.password} onChange={(event) => update('password', event.target.value)} autoComplete="new-password" minLength={8} required /></label>
      <label className="form-field">Ulangi kata sandi<input type="password" value={form.password_confirmation} onChange={(event) => update('password_confirmation', event.target.value)} autoComplete="new-password" minLength={8} required /></label>
      {error && <p className="form-error" role="alert">{error}</p>}
      {success && <p className="form-success" role="status">{success}</p>}
      <button className="primary-button" type="submit" disabled={busy}>{busy ? 'Membuat akun...' : 'Buat akun'}</button>
    </form>
  </section>;
}
