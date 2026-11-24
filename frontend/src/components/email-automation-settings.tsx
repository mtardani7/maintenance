'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { AlertCircle, Building2, CalendarDays, Check, Clock3, RefreshCw, Send, Ticket, UsersRound } from 'lucide-react';
import { apiRequest, ApiError } from '@/lib/api';
import { useAuthUser } from './auth-boundary';

type Settings = {
  ticket: { enabled: boolean; maintenance_enabled: boolean; supervisor_enabled: boolean; plant_recipients: { plant_id: number; code: string; name: string; maintenance: { name: string; email: string }[]; supervisors: { name: string; email: string }[] }[] };
  monthly_report: { auto_enabled: boolean; recipients: string[]; first_sent_at: string | null; last_sent_month: string | null };
  mailer_ready: boolean;
  mailer: string;
};

const empty: Settings = { ticket: { enabled: false, maintenance_enabled: true, supervisor_enabled: true, plant_recipients: [] }, monthly_report: { auto_enabled: false, recipients: [], first_sent_at: null, last_sent_month: null }, mailer_ready: false, mailer: '' };
const parseEmails = (value: string) => [...new Set(value.split(/[\n,;]+/).map((part) => part.trim()).filter(Boolean))];
const apiMessage = (error: unknown) => error instanceof ApiError ? error.payload?.message ?? error.message : 'Pengaturan gagal dimuat. Coba lagi.';

export function EmailAutomationSettings() {
  const user = useAuthUser();
  const [settings, setSettings] = useState<Settings>(empty);
  const [busy, setBusy] = useState(true);
  const [saving, setSaving] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [reportText, setReportText] = useState('');

  async function load() {
    setBusy(true); setError('');
    try {
      const value = await apiRequest<Settings>('/admin/settings/email-automation');
      setSettings(value);
      setReportText(value.monthly_report.recipients.join('\n'));
    } catch (reason) { setError(apiMessage(reason)); } finally { setBusy(false); }
  }

  useEffect(() => { if (user?.role === 'admin') void load(); }, [user?.role]);

  async function persistSettings() {
    const value = await apiRequest<Settings>('/admin/settings/email-automation', { method: 'PUT', body: JSON.stringify({
      ticket: { enabled: settings.ticket.enabled, maintenance_enabled: settings.ticket.maintenance_enabled, supervisor_enabled: settings.ticket.supervisor_enabled },
      monthly_report: { auto_enabled: settings.monthly_report.auto_enabled, recipients: parseEmails(reportText) },
    }) });
    setSettings(value);
    return value;
  }

  async function save(event: FormEvent) {
    event.preventDefault(); setSaving(true); setError(''); setNotice('');
    try { await persistSettings(); setNotice('Pengaturan berhasil disimpan.'); }
    catch (reason) { setError(apiMessage(reason)); }
    finally { setSaving(false); }
  }

  async function sendNow() {
    setSending(true); setError(''); setNotice('');
    try {
      await persistSettings();
      await apiRequest('/admin/settings/monthly-report/send', { method: 'POST', body: JSON.stringify({}) });
      setNotice('Laporan masuk ke antrean pengiriman. Otomatisasi bulanan dimulai setelah email pertama berhasil dikirim.');
      await load();
    } catch (reason) { setError(apiMessage(reason)); }
    finally { setSending(false); }
  }

  if (!user || user.role !== 'admin') return null;
  if (busy) return <div className="email-settings-loading" aria-live="polite"><span className="email-settings-loading__spinner" />Memuat pengaturan email…</div>;

  return <form className="email-settings" onSubmit={(event) => void save(event)}>
    <div className={`email-settings__mailer ${settings.mailer_ready ? 'is-ready' : 'is-unavailable'}`} role="status">
      <span className="email-settings__mailer-icon">{settings.mailer_ready ? <Check aria-hidden="true" /> : <AlertCircle aria-hidden="true" />}</span>
      <span><strong>{settings.mailer_ready ? 'Email siap digunakan' : 'Email belum siap'}</strong><small>{settings.mailer_ready ? `Layanan pengiriman aktif melalui ${settings.mailer || 'SMTP'}.` : 'Konfigurasi SMTP di server perlu dilengkapi sebelum email dapat dikirim.'}</small></span>
      <span className="email-settings__mailer-status">{settings.mailer_ready ? 'Aktif' : 'Perlu konfigurasi'}</span>
    </div>

    <section className="email-settings__card">
      <div className="email-settings__card-heading">
        <span className="email-settings__icon"><Ticket aria-hidden="true" /></span>
        <div><p className="eyebrow">NOTIFIKASI</p><h2>Email tiket baru</h2><p>Kirim pemberitahuan saat laporan baru masuk ke MIRA.</p></div>
      </div>
      <label className="email-settings__switch-row"><span><strong>Aktifkan notifikasi email tiket</strong><small>Notifikasi aplikasi tetap berjalan seperti biasa.</small></span><input className="email-settings__switch" type="checkbox" checked={settings.ticket.enabled} onChange={(e) => setSettings((s) => ({ ...s, ticket: { ...s.ticket, enabled: e.target.checked } }))} aria-label="Aktifkan notifikasi email tiket" /></label>
      <div className="email-settings__scope-controls">
        <label><input type="checkbox" checked={settings.ticket.maintenance_enabled} disabled={!settings.ticket.enabled} onChange={(e) => setSettings((s) => ({ ...s, ticket: { ...s.ticket, maintenance_enabled: e.target.checked } }))} /><span><strong>Maintenance</strong><small>Notifikasi untuk plant masing-masing</small></span></label>
        <label><input type="checkbox" checked={settings.ticket.supervisor_enabled} disabled={!settings.ticket.enabled} onChange={(e) => setSettings((s) => ({ ...s, ticket: { ...s.ticket, supervisor_enabled: e.target.checked } }))} /><span><strong>SPV Maintenance</strong><small>Notifikasi untuk plant masing-masing</small></span></label>
      </div>
      <div className="email-settings__plant-rule"><UsersRound aria-hidden="true" /><span>Penerima mengikuti akun aktif di Master Pengguna. Hanya Maintenance dan SPV yang ditempatkan pada plant yang sama dengan tiket yang akan menerima email.</span></div>
      <div className="email-settings__plant-list">
        {settings.ticket.plant_recipients.length ? settings.ticket.plant_recipients.map((plant) => <article className="email-settings__plant" key={plant.plant_id}>
          <h3><Building2 aria-hidden="true" /><span>{plant.code}<small>{plant.name}</small></span></h3>
          <div className="email-settings__plant-groups">
            <div className={!settings.ticket.maintenance_enabled ? 'is-muted' : ''}><strong><UsersRound aria-hidden="true" />Maintenance</strong>{settings.ticket.maintenance_enabled && plant.maintenance.length ? <ul>{plant.maintenance.map((person) => <li key={person.email}><span>{person.name}</span><small>{person.email}</small></li>)}</ul> : <p>{settings.ticket.maintenance_enabled ? 'Belum ada akun Maintenance aktif untuk plant ini.' : 'Notifikasi Maintenance dinonaktifkan.'}</p>}</div>
            <div className={!settings.ticket.supervisor_enabled ? 'is-muted' : ''}><strong><UsersRound aria-hidden="true" />SPV Maintenance</strong>{settings.ticket.supervisor_enabled && plant.supervisors.length ? <ul>{plant.supervisors.map((person) => <li key={person.email}><span>{person.name}</span><small>{person.email}</small></li>)}</ul> : <p>{settings.ticket.supervisor_enabled ? 'Belum ada akun SPV Maintenance aktif untuk plant ini.' : 'Notifikasi SPV dinonaktifkan.'}</p>}</div>
          </div>
        </article>) : <p className="email-settings__no-plants">Belum ada plant aktif pada Master Plant.</p>}
      </div>
    </section>

    <section className="email-settings__card">
      <div className="email-settings__card-heading">
        <span className="email-settings__icon"><CalendarDays aria-hidden="true" /></span>
        <div><p className="eyebrow">RINGKASAN BERKALA</p><h2>Laporan pemeliharaan bulanan</h2><p>Tentukan penerima, lalu kirim laporan pertama secara manual.</p></div>
      </div>
      <label className="form-field email-settings__report-field">Penerima laporan<textarea rows={3} value={reportText} onChange={(e) => setReportText(e.target.value)} placeholder="nama@perusahaan.co.id" /><small>Pisahkan beberapa alamat dengan koma atau baris baru.</small></label>
      <label className="email-settings__switch-row email-settings__monthly-switch"><span><strong>Kirim otomatis setiap bulan</strong><small>Laporan periode bulan sebelumnya dikirim tanggal 1 pukul 00.05.</small></span><input className="email-settings__switch" type="checkbox" checked={settings.monthly_report.auto_enabled} onChange={(e) => setSettings((s) => ({ ...s, monthly_report: { ...s.monthly_report, auto_enabled: e.target.checked } }))} aria-label="Aktifkan pengiriman laporan otomatis" /></label>
      <div className="email-settings__report-footer">
        <div className="email-settings__last-sent"><Clock3 aria-hidden="true" /><span>{settings.monthly_report.last_sent_month ? `Laporan terakhir: ${settings.monthly_report.last_sent_month}` : 'Belum ada laporan yang dikirim'}</span></div>
        <button className="secondary-action email-settings__send-button" type="button" onClick={() => void sendNow()} disabled={sending || !settings.mailer_ready}><Send aria-hidden="true" />{sending ? 'Menyiapkan pengiriman…' : 'Kirim laporan sekarang'}</button>
      </div>
      {!settings.monthly_report.first_sent_at && <p className="email-settings__first-send-note">Pengiriman otomatis dimulai setelah laporan pertama berhasil dikirim manual.</p>}
    </section>

    {(error || notice) && <div className={`email-settings__feedback ${error ? 'is-error' : 'is-success'}`} role={error ? 'alert' : 'status'}>{error ? <AlertCircle aria-hidden="true" /> : <Check aria-hidden="true" />}{error || notice}</div>}
    <div className="email-settings__actions"><button className="primary-button" type="submit" disabled={saving}>{saving ? <><RefreshCw className="email-settings__spin" aria-hidden="true" />Menyimpan…</> : 'Simpan pengaturan'}</button></div>
  </form>;
}
