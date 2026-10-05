'use client';

import Link from 'next/link';
import { useEffect, useState, type CSSProperties } from 'react';
import { Activity, AlertTriangle, ArrowUpRight, CalendarClock, CheckCheck, ClipboardList, RefreshCw, Ticket, Wrench, X, type LucideIcon } from 'lucide-react';
import { getMaintenanceDashboard, getPlantOptions, type MaintenanceDashboard, type PlantOption } from '@/lib/maintenance-api';
import { CardSkeleton, EmptyState, ErrorState } from './ui';

type PeriodDays = 7 | 30 | 90;
const numberFormat = new Intl.NumberFormat('id-ID');
const dateTimeFormat = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const dateFormat = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
const problemLabels: Record<string, string> = {
  'Machine stopped': 'Mesin berhenti', 'Abnormal sound': 'Suara tidak normal', 'Sensor problem': 'Masalah sensor',
  'Quality problem': 'Masalah mutu', Other: 'Lainnya',
};

function problemLabel(value: string) { return problemLabels[value] ?? value; }

function formatDate(value: string | null | undefined, withTime = false) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : (withTime ? dateTimeFormat : dateFormat).format(date);
}

export function OperationsDashboard() {
  const [plants, setPlants] = useState<PlantOption[]>([]);
  const [plantId, setPlantId] = useState('');
  const [periodDays, setPeriodDays] = useState<PeriodDays>(30);
  const [dashboard, setDashboard] = useState<MaintenanceDashboard | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [showAllMachines, setShowAllMachines] = useState(false);
  const [showAllRepeated, setShowAllRepeated] = useState(false);
  const [selectedMachineId, setSelectedMachineId] = useState<number | null>(null);

  useEffect(() => {
    getPlantOptions().then(setPlants).catch(() => setPlants([]));
  }, []);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError('');
    getMaintenanceDashboard({ period_days: periodDays, plant_id: plantId || undefined })
      .then((result) => { if (active) setDashboard(result); })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : 'Data dashboard belum dapat dimuat.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [periodDays, plantId, refreshKey]);

  const machines = dashboard?.trend_machines ?? [];
  const repeated = dashboard?.repeated_problems ?? [];
  const selectedMachine = machines.find((machine) => machine.machine_id === selectedMachineId) ?? null;
  const maximumCount = Math.max(1, ...machines.map((machine) => machine.incident_count));

  return <div className="operations-dashboard maintenance-dashboard maintenance-insights-dashboard">
    <section className="maintenance-dashboard-header">
      <div><div className="maintenance-dashboard-label"><Activity aria-hidden="true" /> PEMELIHARAAN</div><h1>Dashboard Maintenance</h1><p>Pantau pekerjaan berjalan dan temukan masalah mesin yang berulang.</p></div>
      <button className="maintenance-refresh" type="button" onClick={() => setRefreshKey((value) => value + 1)} disabled={loading}><RefreshCw aria-hidden="true" />{loading ? 'Memuat…' : 'Muat ulang'}</button>
    </section>

    <section className="maintenance-dashboard-filters" aria-label="Filter dashboard">
      <label>Plant<select value={plantId} onChange={(event) => setPlantId(event.target.value)}><option value="">Semua Plant</option>{plants.map((plant) => <option value={plant.id} key={plant.id}>{plant.code} — {plant.name}</option>)}</select></label>
      <label>Periode trend<select value={periodDays} onChange={(event) => setPeriodDays(Number(event.target.value) as PeriodDays)}><option value={7}>7 Hari</option><option value={30}>30 Hari</option><option value={90}>90 Hari</option></select></label>
    </section>

    {error ? <ErrorState title="Dashboard tidak tersedia" description="Data belum dapat dimuat. Periksa koneksi lalu coba lagi." onRetry={() => setRefreshKey((value) => value + 1)} /> : <>
      <section className="maintenance-kpis" aria-label="Ringkasan operasional">
        {loading || !dashboard ? <>{[0, 1, 2, 3].map((item) => <CardSkeleton className="maintenance-kpi" key={item} />)}</> : <>
          <KpiCard label="Tiket terbuka" value={dashboard.summary.open_tickets} icon={Ticket} />
          <KpiCard label="Tiket selesai hari ini" value={dashboard.summary.closed_today} icon={CheckCheck} />
          <KpiCard label="Tiket lebih dari 1 hari" value={dashboard.summary.overdue_tickets} icon={CalendarClock} />
          <KpiCard label="Insiden hari ini" value={dashboard.summary.incidents_today} icon={AlertTriangle} />
        </>}
      </section>

      <section className="maintenance-dashboard-section">
        <div className="maintenance-section-title"><h2>Perlu Ditangani</h2><span>{dashboard?.summary.open_tickets ?? 0} tiket terbuka</span></div>
        {loading ? <div className="maintenance-attention-skeleton"><CardSkeleton /><CardSkeleton /><CardSkeleton /></div> : dashboard?.needs_attention.length ? <div className="maintenance-attention-list">{dashboard.needs_attention.map((ticket) => <Link key={ticket.ticket_number} href={`/tickets/${encodeURIComponent(ticket.ticket_number)}`} className="maintenance-attention-row">
          <span className="maintenance-attention-icon"><Wrench aria-hidden="true" /></span><span className="maintenance-attention-main"><strong>{ticket.ticket_number}</strong><small>{problemLabel(ticket.problem_type)} · {ticket.machine_code ?? ticket.machine_name ?? 'Mesin —'}{ticket.plant_name ? ` · ${ticket.plant_name}` : ''}</small></span><span className="maintenance-attention-age">{formatDate(ticket.created_at)}</span><ArrowUpRight aria-hidden="true" className="maintenance-attention-arrow" />
        </Link>)}</div> : <EmptyState title="Tidak ada pekerjaan tertunda" description="Semua tiket terbuka akan muncul di bagian ini." />}
      </section>

      <section className="maintenance-trend-grid" aria-label="Analisis masalah mesin">
        <article className="surface-card maintenance-analysis-card">
          <header><div><h2>Trend Masalah Mesin</h2><p>Mesin yang paling sering mengalami masalah · {periodDays} hari</p></div></header>
          {loading ? <div className="maintenance-analysis-skeleton"><CardSkeleton /><CardSkeleton /><CardSkeleton /></div> : machines.length === 0 ? <p className="maintenance-analysis-empty">Belum ada data masalah mesin pada periode ini.</p> : <>
            <ol className="maintenance-trend-list">{(showAllMachines ? machines : machines.slice(0, 5)).map((machine, index) => <li key={machine.machine_id}><button type="button" className="maintenance-trend-row" onClick={() => setSelectedMachineId(machine.machine_id)}>
              <span className="maintenance-trend-rank">{index + 1}</span><span className="maintenance-trend-machine"><strong>{machine.machine_code}</strong><small>{machine.machine_name}</small><span className="maintenance-trend-bar"><i style={{ '--trend-width': `${machine.incident_count / maximumCount * 100}%` } as CSSProperties} /></span></span><b>{numberFormat.format(machine.incident_count)} <small>masalah</small></b>
            </button></li>)}</ol>
            {machines.length > 5 && <button className="maintenance-analysis-more" type="button" onClick={() => setShowAllMachines((value) => !value)}>{showAllMachines ? 'Tampilkan lebih sedikit' : 'Lihat semua'}</button>}
          </>}
        </article>

        <article className="surface-card maintenance-analysis-card">
          <header><div><h2>Masalah Berulang</h2><p>Masalah sejenis pada mesin yang sama lebih dari sekali</p></div></header>
          {loading ? <div className="maintenance-analysis-skeleton"><CardSkeleton /><CardSkeleton /><CardSkeleton /></div> : repeated.length === 0 ? <p className="maintenance-analysis-empty">Belum ada masalah berulang pada periode ini.</p> : <>
            <ol className="maintenance-repeat-list">{(showAllRepeated ? repeated : repeated.slice(0, 5)).map((problem) => <li key={`${problem.machine_id}:${problem.problem_type}`}><div><strong>{problem.machine_code}</strong><span>{problemLabel(problem.problem_type)}</span><small>{numberFormat.format(problem.count)} kejadian · terakhir {formatDate(problem.last_occurred_at)}</small></div><b>{problem.count}×</b></li>)}</ol>
            {repeated.length > 5 && <button className="maintenance-analysis-more" type="button" onClick={() => setShowAllRepeated((value) => !value)}>{showAllRepeated ? 'Tampilkan lebih sedikit' : 'Lihat semua'}</button>}
          </>}
        </article>
      </section>

      <section className="maintenance-dashboard-section">
        <div className="maintenance-section-title"><h2>Aktivitas Terbaru</h2></div>
        {loading ? <div className="maintenance-analysis-skeleton"><CardSkeleton /><CardSkeleton /></div> : dashboard?.recent_activity.length ? <div className="maintenance-recent-list">{dashboard.recent_activity.map((item) => <div className="maintenance-recent-row" key={item.id}><span className="maintenance-recent-icon"><ClipboardList aria-hidden="true" /></span><span><strong>{problemLabel(item.problem_type)}</strong><small>{item.machine_code} · {item.machine_name}</small></span><time>{formatDate(item.created_at, true)}</time>{item.ticket_number && <Link href={`/tickets/${encodeURIComponent(item.ticket_number)}`}>{item.ticket_number}</Link>}</div>)}</div> : <p className="maintenance-analysis-empty">Belum ada aktivitas terbaru.</p>}
      </section>
    </>}

    {selectedMachine && <div className="maintenance-machine-modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setSelectedMachineId(null); }}><section className="surface-card maintenance-machine-modal" role="dialog" aria-modal="true" aria-labelledby="maintenance-machine-title">
      <button type="button" className="maintenance-machine-modal-close" onClick={() => setSelectedMachineId(null)} aria-label="Tutup"><X aria-hidden="true" /></button>
      <p className="eyebrow">Ringkasan mesin</p><h2 id="maintenance-machine-title">{selectedMachine.machine_code}</h2><p className="maintenance-machine-modal-subtitle">{selectedMachine.machine_name}{selectedMachine.plant_name ? ` · ${selectedMachine.plant_name}` : ''}</p>
      <div className="maintenance-machine-total"><span>Total masalah</span><strong>{selectedMachine.incident_count}</strong></div>
      <h3>Masalah berdasarkan jenis</h3><ul>{selectedMachine.problems.map((problem, index) => <li key={problem.problem_type}><span>{index === 0 ? 'Paling sering · ' : ''}{problemLabel(problem.problem_type)}</span><b>{problem.count}×</b></li>)}</ul>
      <p className="maintenance-machine-last">Terakhir terjadi <strong>{formatDate(selectedMachine.last_occurred_at, true)}</strong></p>
      <button className="primary-button" type="button" onClick={() => setSelectedMachineId(null)}>Tutup</button>
    </section></div>}
  </div>;
}

function KpiCard({ label, value, icon: Icon }: { label: string; value: number; icon: LucideIcon }) {
  return <article className="maintenance-kpi"><div><span>{label}</span><strong>{numberFormat.format(value)}</strong></div><Icon aria-hidden="true" /></article>;
}
