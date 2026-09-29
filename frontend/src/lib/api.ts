import type { ApiErrorPayload } from './types';

const apiBaseUrl = process.env.NEXT_PUBLIC_API_URL?.replace(/\/$/, '') ?? '';
function accessToken() {
  return typeof window === 'undefined' ? '' : window.localStorage.getItem('maintenance_token') ?? '';
}

export class ApiError extends Error {
  status: number;
  payload?: ApiErrorPayload;

  constructor(message: string, status: number, payload?: ApiErrorPayload) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.payload = payload;
  }
}

export class ApiConfigurationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ApiConfigurationError';
  }
}

export async function apiRequest<T>(path: string, init?: RequestInit): Promise<T> {
  if (!apiBaseUrl) {
    throw new ApiConfigurationError('NEXT_PUBLIC_API_URL is not configured.');
  }

  const isFormData = typeof FormData !== 'undefined' && init?.body instanceof FormData;
  const token = accessToken();
  const response = await fetch(`${apiBaseUrl}${path}`, {
    ...init,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(init?.body && !isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...init?.headers,
    },
  });

  if (!response.ok) {
    if ([401, 419].includes(response.status) && typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('maintenance:session-expired', { detail: { path } }));
    }
    let payload: ApiErrorPayload | undefined;
    try {
      payload = (await response.json()) as ApiErrorPayload;
    } catch {
      payload = undefined;
    }
    throw new ApiError(payload?.message ?? 'The API request could not be completed.', response.status, payload);
  }

  if (response.status === 204) return undefined as T;
  return (await response.json()) as T;
}

export async function apiDownload(path: string, init?: RequestInit): Promise<Blob> {
  if (!apiBaseUrl) {
    throw new ApiConfigurationError('NEXT_PUBLIC_API_URL is not configured.');
  }
  const response = await fetch(`${apiBaseUrl}${path}`, {
    ...init,
    credentials: 'same-origin',
    headers: { Accept: 'text/csv, application/octet-stream', ...(accessToken() ? { Authorization: `Bearer ${accessToken()}` } : {}), ...init?.headers },
  });
  if (!response.ok) throw new ApiError('The API download could not be completed.', response.status);
  return response.blob();
}
