import { apiRequest, ApiConfigurationError, ApiError } from './api';
import type { AuthResult, User } from './types';

const mePath = process.env.NEXT_PUBLIC_AUTH_ME_PATH ?? '/me';
const loginPath = process.env.NEXT_PUBLIC_AUTH_LOGIN_PATH ?? '/login';
const logoutPath = process.env.NEXT_PUBLIC_AUTH_LOGOUT_PATH ?? '/logout';

type CurrentUserRequest = { token: string; promise: Promise<AuthResult> };
let currentUserCache: { token: string; result: AuthResult } | null = null;
let currentUserRequest: CurrentUserRequest | null = null;

function currentToken(): string {
  return typeof window === 'undefined' ? '' : window.localStorage.getItem('maintenance_token') ?? '';
}

export function setCurrentUser(user: User): void {
  currentUserCache = { token: currentToken(), result: { status: 'authenticated', user } };
  currentUserRequest = null;
}

export function clearCurrentUser(): void {
  currentUserCache = null;
  currentUserRequest = null;
}

export function clearAuthSession(): void {
  clearCurrentUser();
  if (typeof window !== 'undefined') window.localStorage.removeItem('maintenance_token');
}

export async function getCurrentUser(options: { force?: boolean } = {}): Promise<AuthResult> {
  const token = currentToken();
  if (!options.force && currentUserCache?.token === token) {
    if (process.env.NODE_ENV === 'development') console.debug('[auth timing] current-user cache hit');
    return currentUserCache.result;
  }
  if (currentUserRequest?.token === token) {
    if (process.env.NODE_ENV === 'development') console.debug('[auth timing] current-user request shared');
    return currentUserRequest.promise;
  }

  const startedAt = typeof performance === 'undefined' ? 0 : performance.now();
  if (process.env.NODE_ENV === 'development') console.debug('[auth timing] current-user request started');
  const promise = requestCurrentUser();
  const request = { token, promise };
  currentUserRequest = request;
  const result = await promise;
  if (currentUserRequest === request) currentUserRequest = null;
  if (result.status !== 'unavailable' && token === currentToken()) currentUserCache = { token, result };
  if (process.env.NODE_ENV === 'development') {
    const duration = typeof performance === 'undefined' ? 0 : Math.round(performance.now() - startedAt);
    console.debug(`[auth timing] current-user ${result.status} in ${duration}ms`);
  }
  return result;
}

async function requestCurrentUser(): Promise<AuthResult> {
  if (!mePath) {
    return { status: 'unavailable', message: 'Laravel auth endpoint is not configured yet.' };
  }

  try {
    const result = await apiRequest<{ user: User }>(mePath);
    return { status: 'authenticated', user: result.user };
  } catch (error) {
    if (error instanceof ApiError && [401, 419].includes(error.status)) {
      return { status: 'unauthenticated' };
    }
    if (error instanceof ApiConfigurationError) {
      return { status: 'unavailable', message: error.message };
    }
    return { status: 'unavailable', message: 'Unable to reach the Laravel authentication service.' };
  }
}

export async function login(email: string, password: string): Promise<User> {
  if (!loginPath) {
    throw new ApiConfigurationError('Laravel login endpoint is not configured yet.');
  }

  const result = await apiRequest<{ token: string; user: User }>(loginPath, {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  });
  if (typeof window !== 'undefined') window.localStorage.setItem('maintenance_token', result.token);
  setCurrentUser(result.user);
  return result.user;
}

export async function logout(): Promise<void> {
  try { await apiRequest<void>(logoutPath, { method: 'POST' }); } finally {
    clearAuthSession();
  }
}
