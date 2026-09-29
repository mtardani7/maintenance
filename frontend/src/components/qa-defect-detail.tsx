'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { createMaintenanceTicketFromDefect, getQADefect, getQADefectMaintenanceTicket, qaApiMessage } from '@/lib/qa-api';
import type { QADefect } from '@/lib/qa-types';
import type { Ticket } from '@/lib/ticket-types';
import { DetailSkeleton, ErrorState } from './ui';

const severityLabels: Record<string, string> = { CRITICAL: 'Kritis', HIGH: 'Tinggi', MEDIUM: 'Sedang', LOW: 'Rendah' };
const statusLabels: Record<string, string> = { OPEN: 'Terbuka', CLOSED: 'Ditutup' };

export function QADefectDetail({ defectId }: { defectId: string }) {
  const [defect, setDefect] = useState<QADefect | null>(null);
  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [checking, setChecking] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => { setChecking(true); setError(''); setDefect(null); setTicket(null); getQADefect(defectId).then((result) => { setDefect(result); return getQADefectMaintenanceTicket(result.id); }).then(setTicket).catch((reason) => setError(qaApiMessage(reason))).finally(() => setChecking(false)); }, [defectId, retryKey]);
  if (error && !defect) return <ErrorState title="Temuan mutu tidak tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => setRetryKey((value) => value + 1)} />;
  if (!defect || checking) return <DetailSkeleton sections={["Informasi temuan", "Tindak lanjut"]} />;
  async function createTicket() { setBusy(true); setError(''); try { const created = await createMaintenanceTicketFromDefect(defect!); setTicket(created); setSuccess(`Tiket ${created.number} berhasil dibuat.`); } catch (reason) { setError(qaApiMessage(reason)); } finally { setBusy(false); } }

  return <div className="qa-detail-layout"><main><div className="qa-detail-hero"><div><p className="eyebrow">Temuan mutu · {defect.defectId}</p><h1>{defect.defectType}</h1><p>{defect.machine.code} / {defect.machine.name} / {defect.plant}</p></div><span className={`qa-severity qa-severity--${defect.severity.toLowerCase()}`}>{severityLabels[defect.severity] ?? defect.severity}</span></div><section className="detail-card"><h2>Informasi temuan</h2><dl className="machine-facts"><div><dt>Nomor temuan</dt><dd>{defect.defectId}</dd></div><div><dt>Mesin</dt><dd>{defect.machine.code} / {defect.machine.name}</dd></div><div><dt>Plant</dt><dd>{defect.plant}</dd></div><div><dt>Tanggal pemeriksaan</dt><dd>{defect.inspectionDate}</dd></div><div><dt>Jumlah</dt><dd>{defect.quantity}</dd></div><div><dt>Pemeriksa</dt><dd>{defect.inspector.name}</dd></div></dl><div className="ticket-description"><dt>Deskripsi</dt><p>{defect.description}</p></div></section></main><aside className="qa-detail-aside"><section className="action-card"><p className="eyebrow">Tindak lanjut</p>{ticket ? <><h2>Tiket pemeliharaan</h2><strong className="qa-ticket-number">{ticket.number}</strong><p>Status: <b className={`ticket-status ticket-status--${ticket.status.toLowerCase()}`}>{statusLabels[ticket.status] ?? ticket.status}</b></p><Link className="primary-button primary-button--link" href={`/tickets/${ticket.id}`}>Buka tiket</Link></> : <><h2>Belum ada tiket</h2><p>Buat tiket pemeliharaan dari temuan ini. Informasi mesin dan masalah akan disertakan.</p><button className="primary-button" type="button" disabled={busy} onClick={() => void createTicket()}>{busy ? 'Membuat tiket…' : 'Buat tiket pemeliharaan'}</button></>}{success && <p className="qa-success" role="status">{success}</p>}{error && <ErrorState title="Tiket belum dibuat" description={error} />}</section><Link className="back-link" href="/qa">Kembali ke temuan mutu</Link></aside></div>;
}
