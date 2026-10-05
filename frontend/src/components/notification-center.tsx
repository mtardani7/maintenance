'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import type { MouseEvent } from 'react';
import { getNotifications, markAllNotificationsRead, markNotificationRead, notificationApiMessage, relativeNotificationTime } from '@/lib/notification-api';
import type { AppNotification } from '@/lib/notification-types';
import { EmptyState, ErrorState, ListSkeleton } from './ui';

const notificationLabels: Record<string, string> = { ticket_created: 'Tiket dibuat', ticket_assigned: 'Tiket ditugaskan', ticket_overdue: 'Tiket terlambat', ticket_closed: 'Tiket ditutup', incident_created: 'Laporan dibuat', maintenance_update: 'Pembaruan pemeliharaan' };

export function NotificationCenter() {
  const [items, setItems] = useState<AppNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  async function refresh() {
    setLoading(true); setError('');
    try { const page = await getNotifications(); setItems(page.data); setUnread(page.unreadCount); }
    catch (reason) { setError(notificationApiMessage(reason)); }
    finally { setLoading(false); }
  }
  useEffect(() => { void refresh(); }, []);
  async function read(item: AppNotification) { if (!item.read) { try { await markNotificationRead(item.id); setItems((current) => current.map((entry) => entry.id === item.id ? { ...entry, read: true } : entry)); setUnread((current) => Math.max(0, current - 1)); } catch (reason) { setError(notificationApiMessage(reason)); return false; } } return true; }
  async function openNotification(event: MouseEvent<HTMLAnchorElement>, item: AppNotification) { event.preventDefault(); if (await read(item)) window.location.assign(item.url ?? (item.relatedTicketId ? `/tickets/${item.relatedTicketId}` : '/notifications')); }
  async function readAll() { try { await markAllNotificationsRead(); setItems((current) => current.map((item) => ({ ...item, read: true }))); setUnread(0); } catch (reason) { setError(notificationApiMessage(reason)); } }

  return <div className="notification-center"><div className="notification-toolbar"><div><p className="eyebrow">Pusat notifikasi</p><p className="notification-count">{loading ? 'Memuat pembaruan...' : `${unread} belum dibaca`}</p></div><button className="secondary-action" type="button" disabled={loading || !unread} onClick={readAll}>Tandai semua dibaca</button></div>{loading ? <ListSkeleton rows={5} className="notification-list" /> : error ? <ErrorState title="Notifikasi tidak tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => void refresh()} /> : items.length === 0 ? <EmptyState title="Belum ada notifikasi" description="Pembaruan tiket dan pemeliharaan akan tampil di sini." /> : <div className="notification-list">{items.map((item) => <div className={`notification-item ${item.read ? '' : 'notification-item--unread'}`} key={item.id}><Link href={item.url ?? (item.relatedTicketId ? `/tickets/${item.relatedTicketId}` : '/notifications')} onClick={(event) => openNotification(event, item)}><span className="notification-type">{notificationLabels[item.type] ?? item.type.replaceAll('_', ' ')}</span><strong>{item.title}</strong>{item.ticketNumber && <span className="notification-ticket-number">{item.ticketNumber}</span>}<p>{item.machine ? `${item.machine}${item.problem ? ` · ${item.problem}` : ''}` : item.body}</p>{item.solution && <p>{item.solution}</p>}<small>{relativeNotificationTime(item.createdAt)} · {item.read ? 'Sudah dibaca' : 'Belum dibaca'}</small></Link>{!item.read && <button className="notification-read-button" aria-label="Tandai notifikasi sudah dibaca" onClick={() => read(item)}>Tandai dibaca</button>}</div>)}</div>}</div>;
}
