import { apiRequest, ApiConfigurationError, ApiError } from './api';
import type { AppNotification, NotificationPage } from './notification-types';

const paths = {
  list: process.env.NEXT_PUBLIC_NOTIFICATIONS_PATH ?? '/notifications',
  unreadCount: process.env.NEXT_PUBLIC_NOTIFICATIONS_UNREAD_COUNT_PATH ?? '/notifications/unread-count',
  read: process.env.NEXT_PUBLIC_NOTIFICATION_READ_PATH_TEMPLATE ?? '/notifications/{id}/read',
  readAll: process.env.NEXT_PUBLIC_NOTIFICATIONS_READ_ALL_PATH ?? '/notifications/read-all',
};

type NotificationResponse = AppNotification[] | { data: AppNotification[]; current_page?: number; last_page?: number; meta?: { current_page?: number; last_page?: number; unread_count?: number }; unread_count?: number };

function normalize(response: NotificationResponse): NotificationPage {
  if (Array.isArray(response)) return { data: response, unreadCount: response.filter((item) => !item.read).length, currentPage: 1, lastPage: 1 };
  return { data: response.data, unreadCount: response.unread_count ?? response.meta?.unread_count ?? response.data.filter((item) => !item.read).length, currentPage: response.current_page ?? response.meta?.current_page ?? 1, lastPage: response.last_page ?? response.meta?.last_page ?? 1 };
}
export async function getNotifications(page = 1): Promise<NotificationPage> {
  const path = paths.list;
  return normalize(await apiRequest<NotificationResponse>(`${path}${path.includes('?') ? '&' : '?'}page=${page}`));
}

export async function getUnreadNotificationCount() {
  const response = await apiRequest<{ count: number }>(paths.unreadCount);
  return response.count;
}

export async function markNotificationRead(id: AppNotification['id']) {
  return apiRequest<AppNotification>(paths.read.replace('{id}', encodeURIComponent(String(id))), { method: 'PATCH' });
}

export async function markAllNotificationsRead() {
  return apiRequest<void>(paths.readAll, { method: 'PATCH' });
}

export function relativeNotificationTime(value: string) {
  const timestamp = new Date(value).getTime();
  if (!Number.isFinite(timestamp)) return value;
  const seconds = Math.round((timestamp - Date.now()) / 1000);
  const units: [Intl.RelativeTimeFormatUnit, number][] = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
  const formatter = new Intl.RelativeTimeFormat('id', { numeric: 'auto' });
  for (const [unit, size] of units) if (Math.abs(seconds) >= size) return formatter.format(Math.round(seconds / size), unit);
  return formatter.format(seconds, 'second');
}

export function notificationApiMessage(error: unknown) {
  if (error instanceof ApiError) {
    if (error.status === 401) return 'Your session has expired. Sign in again to continue.';
    if (error.status === 403) return 'You do not have permission to manage notifications.';
    if (error.status === 404) return 'The notification could not be found.';
    if (error.status === 422) return error.message || 'The notification request was not valid.';
    if (error.status >= 500) return 'The notification service is having trouble. Try again shortly.';
  }
  if (error instanceof ApiConfigurationError) return error.message;
  if (error instanceof TypeError) return 'The notification service could not be reached. Check your connection.';
  return 'The notification request could not be completed.';
}
