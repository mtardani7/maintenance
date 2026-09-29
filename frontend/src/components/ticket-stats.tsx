'use client';

import { useEffect, useState } from 'react';
import { apiMessage, getTicketStats, type TicketStats } from '@/lib/ticket-api';
import { CardSkeleton, ErrorState } from './ui';

const metrics: { key: keyof TicketStats; label: string }[] = [
  { key: 'open', label: 'Terbuka' },
  { key: 'unassigned', label: 'Belum ditugaskan' },
  { key: 'assigned', label: 'Ditugaskan' },
  { key: 'inProgress', label: 'Sedang dikerjakan' },
  { key: 'overdue', label: 'Terlambat' },
  { key: 'resolvedToday', label: 'Selesai hari ini' },
];

export function TicketStatsCards() {
  const [stats, setStats] = useState<TicketStats | null>(null);
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => { setError(''); getTicketStats().then(setStats).catch((reason) => setError(apiMessage(reason))); }, [retryKey]);
  if (error) return <ErrorState title="Statistik tiket tidak tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => setRetryKey((value) => value + 1)} />;
  if (!stats) return <div className="metric-grid ticket-stats-grid" role="status" aria-label="Memuat statistik tiket">{metrics.map((metric) => <CardSkeleton className="metric-card" key={metric.key} />)}</div>;
  return <div className="metric-grid ticket-stats-grid">{metrics.map((metric) => <article className="metric-card" key={metric.key}><span className="metric-card__label">{metric.label}</span><strong className="metric-card__value">{stats[metric.key]}</strong></article>)}</div>;
}
