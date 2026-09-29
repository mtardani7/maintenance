'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { getSupervisorOverview, operationsApiMessage } from '@/lib/operations-api';
import type { SupervisorOverview } from '@/lib/operations-types';
import { EmptyState, ErrorState, ListSkeleton, TableSkeleton } from './ui';

export function SupervisorDashboard() {
  const [overview, setOverview] = useState<SupervisorOverview | null>(null);
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);
  useEffect(() => { setError(''); setOverview(null); getSupervisorOverview().then(setOverview).catch((reason) => setError(operationsApiMessage(reason))); }, [retryKey]);
  if (error) return <div className="supervisor-dashboard"><ErrorState title="Ringkasan supervisor tidak tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => setRetryKey((value) => value + 1)} /></div>;
  if (!overview) return <div className="supervisor-dashboard supervisor-dashboard--loading"><div className="supervisor-grid">{["Tiket belum ditugaskan", "Tiket kritis", "Tiket terlambat"].map((title) => <section className="work-panel supervisor-queue" key={title}><div className="work-panel__header"><h3>{title}</h3></div><ListSkeleton rows={3} /></section>)}</div><section className="work-panel workload-panel"><div className="work-panel__header"><h3>Beban kerja teknisi</h3></div><TableSkeleton variant="grid" className="workload-table" headerClassName="workload-row" rowClassName="workload-row" headers={["Teknisi", "Ditugaskan", "Dikerjakan", "Terlambat", "Selesai"]} rows={5} /></section><section className="work-panel"><div className="work-panel__header"><h3>Gangguan mesin berulang</h3></div><ListSkeleton rows={3} /></section></div>;
  return <div className="supervisor-dashboard">
    <div className="supervisor-grid">
      <Queue title="Tiket belum ditugaskan" tickets={overview.unassigned} />
      <Queue title="Tiket kritis" tickets={overview.critical} />
      <Queue title="Tiket terlambat" tickets={overview.overdue} />
    </div>
    <section className="work-panel workload-panel"><div className="work-panel__header"><h3>Beban kerja teknisi</h3><span>Penugasan aktif</span></div>{overview.workload.length ? <div className="workload-table">{overview.workload.map((row) => <div className="workload-row" key={row.technicianId}><strong>{row.technician}</strong><span>Ditugaskan <b>{row.assigned}</b></span><span>Dikerjakan <b>{row.inProgress}</b></span><span>Terlambat <b className={row.overdue ? 'sla-overdue' : ''}>{row.overdue}</b></span><span>Selesai <b>{row.resolved}</b></span></div>)}</div> : <EmptyState title="Belum ada data beban kerja" description="Ringkasan akan muncul saat teknisi menerima penugasan." />}</section>
    <section className="work-panel"><div className="work-panel__header"><h3>Gangguan mesin berulang</h3></div>{overview.repeatedFailures.length ? overview.repeatedFailures.map((item) => <div className="report-row" key={item.machineId}><span>{item.machine}</span><strong>{item.count} gangguan</strong></div>) : <EmptyState title="Belum ada gangguan berulang" description="Belum ada mesin dengan gangguan berulang." />}</section>
  </div>;
}

function Queue({ title, tickets }: { title: string; tickets: SupervisorOverview['overdue'] }) {
  return <section className="work-panel supervisor-queue"><div className="work-panel__header"><h3>{title}</h3><span>{tickets.length}</span></div>{tickets.length ? tickets.slice(0, 5).map((ticket) => <Link href={`/tickets/${ticket.id}`} className="report-row" key={ticket.id}><span><strong>{ticket.number}</strong><small>{ticket.machine}</small></span><b className={`ticket-status ticket-status--${ticket.status.toLowerCase()}`}>{ticket.status === 'OPEN' ? 'Terbuka' : 'Ditutup'}</b></Link>) : <EmptyState title="Tidak ada tiket saat ini" description="Semua tiket pada antrean ini sudah ditangani." />}</section>;
}
