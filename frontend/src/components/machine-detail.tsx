'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { getMachineDetail } from '@/lib/maintenance-api';
import type { MachineDetail, MachineStatus } from '@/lib/maintenance-types';
import { DetailSkeleton, EmptyState, ErrorState } from './ui';

const statusLabels: Record<string, string> = { running: 'Aktif', stopped: 'Berhenti', maintenance: 'Dalam perbaikan', offline: 'Nonaktif', unknown: 'Tidak diketahui' };
const problemLabels: Record<string, string> = { 'Machine stopped': 'Mesin berhenti', 'Abnormal sound': 'Suara tidak normal', 'Sensor problem': 'Masalah sensor', 'Quality problem': 'Masalah mutu', Other: 'Lainnya' };
function plantLabel(plant: unknown) {
  if (typeof plant === 'string') return plant;
  if (plant && typeof plant === 'object' && 'name' in plant && typeof plant.name === 'string') return plant.name;
  if (plant && typeof plant === 'object' && 'code' in plant && typeof plant.code === 'string') return plant.code;
  return 'Plant belum diketahui';
}

export function MachineDetailView({ machineId }: { machineId: string }) {
  const [machine, setMachine] = useState<MachineDetail | null>(null);
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);
  useEffect(() => { setMachine(null); setError(''); getMachineDetail(machineId).then(setMachine).catch(() => setError('Detail mesin tidak dapat dimuat.')); }, [machineId, retryKey]);
  if (error) return <ErrorState title="Detail mesin tidak tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => setRetryKey((value) => value + 1)} />;
  if (!machine) return <DetailSkeleton sections={["Informasi mesin", "Tiket pemeliharaan", "Temuan mutu", "Insiden terbaru"]} />;
  const status = machine.status ?? (machine.is_active ? 'running' : 'offline');
  const statusLabel = statusLabels[status] ?? status;
  return <div className="detail-layout"><div className="detail-main">
    <div className="detail-hero"><div><p className="eyebrow">{machine.code}</p><h1>{machine.name}</h1><p>{plantLabel(machine.plant)}{machine.line ? ` · ${machine.line}` : ''}{machine.location ? ` · ${machine.location}` : ''}</p></div><span className={`large-status large-status--${status}`}>{statusLabel}</span></div>
    {machine.analytics && <section className="machine-analytics"><Metric label="Total insiden" value={machine.analytics.totalIncidents} /><Metric label="Tiket pemeliharaan" value={machine.analytics.maintenanceTickets} /><Metric label="Tiket terbuka" value={machine.analytics.openTickets} /><Metric label="Gangguan berulang" value={machine.analytics.repeatFailures} /><Metric label="Total waktu henti" value={machine.analytics.totalDowntime} /><Metric label="Pemeliharaan terakhir" value={machine.analytics.latestMaintenance} /></section>}
    <section className="detail-card"><h2>Informasi mesin</h2><dl className="machine-facts"><div><dt>Kode mesin</dt><dd>{machine.code}</dd></div><div><dt>Plant</dt><dd>{plantLabel(machine.plant)}</dd></div><div><dt>Line</dt><dd>{machine.line || '—'}</dd></div><div><dt>Lokasi</dt><dd>{machine.location || '—'}</dd></div><div><dt>Status saat ini</dt><dd>{statusLabel}</dd></div></dl></section>
    <section className="detail-card"><div className="detail-card__heading"><h2>Tiket pemeliharaan</h2><span>{machine.openTickets.length} terbuka</span></div>{machine.openTickets.length ? <div className="activity-list">{machine.openTickets.map((ticket) => <Link href={`/tickets/${ticket.id}`} key={ticket.id}><strong>{problemLabels[ticket.problemType] ?? ticket.problemType}</strong><p>{ticket.status === 'OPEN' ? 'Terbuka' : ticket.status === 'CLOSED' ? 'Ditutup' : ticket.status ?? 'Terbuka'}</p><small>Buka detail tiket</small></Link>)}</div> : <EmptyState title="Belum ada tiket terbuka" description="Tiket pemeliharaan untuk mesin ini akan tampil di sini." />}</section>
    <section className="detail-card"><div className="detail-card__heading"><h2>Temuan mutu</h2><span>{machine.qaDefects?.length ?? 0}</span></div>{machine.qaDefects?.length ? <div className="activity-list">{machine.qaDefects.map((defect) => <Link href={`/qa/defects/${defect.id}`} key={defect.id}><strong>{defect.defectId}</strong><p>{defect.defectType}</p><small>{defect.maintenanceTicket ? `${defect.maintenanceTicket.number} / ${defect.maintenanceTicket.status}` : 'Belum ada tiket pemeliharaan'}</small></Link>)}</div> : <EmptyState title="Belum ada temuan mutu" description="Temuan mutu terkait mesin ini akan tampil di sini." />}</section>
    <section className="detail-card"><div className="detail-card__heading"><h2>Insiden terbaru</h2><span>{machine.recentIncidents.length}</span></div>{machine.recentIncidents.length ? <div className="activity-list">{machine.recentIncidents.map((incident) => <article key={incident.id}><strong>{problemLabels[incident.problemType] ?? incident.problemType}</strong><p>{incident.description || 'Tidak ada keterangan tambahan.'}</p><small>{incident.createdAt}</small></article>)}</div> : <EmptyState title="Belum ada insiden" description="Riwayat laporan untuk mesin ini akan tampil di sini." />}</section>
  </div><aside className="detail-aside"><div className="action-card"><p className="eyebrow">Laporan operator</p><h2>Ada masalah?</h2><p>Buat laporan agar tim pemeliharaan dapat menindaklanjuti.</p><Link className="primary-button primary-button--link" href={`/incidents?machine=${encodeURIComponent(String(machine.id))}`}>Buat Laporan</Link></div><Link className="back-link" href="/machines">Kembali ke daftar mesin</Link></aside></div>;
}

function Metric({ label, value }: { label: string; value?: string | number }) { return <div><span>{label}</span><strong>{value ?? '—'}</strong></div>; }
export function machineStatusLabel(status: MachineStatus) { return statusLabels[status] ?? 'Tidak diketahui'; }
