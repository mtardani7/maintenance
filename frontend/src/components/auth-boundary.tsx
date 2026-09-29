'use client';

import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { useRouter } from 'next/navigation';
import { clearAuthSession, getCurrentUser } from '@/lib/auth';
import type { User } from '@/lib/types';
import { ErrorState, LoadingState } from './ui';

const AuthUserContext = createContext<User | null>(null);

export function useAuthUser(): User | null {
  return useContext(AuthUserContext);
}

export function AuthBoundary({ children }: { children: ReactNode }) {
  const router = useRouter();
  const [user, setUser] = useState<User | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [message, setMessage] = useState('');
  const [retryKey, setRetryKey] = useState(0);
  const initializationStartedAt = useRef<number | null>(null);
  const protectedRenderLogged = useRef(false);
  const sessionExpiredHandled = useRef(false);

  useEffect(() => {
    let active = true;
    const startedAt = performance.now();
    initializationStartedAt.current = startedAt;
    if (process.env.NODE_ENV === 'development') console.debug('[auth timing] initialization started');
    getCurrentUser({ force: retryKey > 0 }).then((result) => {
      if (!active) return;
      if (process.env.NODE_ENV === 'development') console.debug(`[auth timing] initialization ${result.status} in ${Math.round(performance.now() - startedAt)}ms`);
      if (result.status === 'authenticated') {
        setUser(result.user);
        setStatus('ready');
      } else if (result.status === 'unauthenticated') {
        clearAuthSession();
        router.replace(`/login?next=${encodeURIComponent(window.location.pathname)}`);
      } else {
        setMessage(result.message);
        setStatus('error');
      }
    });

    return () => { active = false; };
  }, [router, retryKey]);

  useEffect(() => {
    const handleSessionExpired = () => {
      if (status !== 'ready' || sessionExpiredHandled.current) return;
      sessionExpiredHandled.current = true;
      clearAuthSession();
      setUser(null);
      setStatus('loading');
      router.replace(`/login?next=${encodeURIComponent(window.location.pathname)}`);
    };
    window.addEventListener('maintenance:session-expired', handleSessionExpired);
    return () => window.removeEventListener('maintenance:session-expired', handleSessionExpired);
  }, [router, status]);

  useEffect(() => {
    if (status !== 'ready' || protectedRenderLogged.current || process.env.NODE_ENV !== 'development') return;
    protectedRenderLogged.current = true;
    const startedAt = initializationStartedAt.current;
    if (startedAt !== null) console.debug(`[auth timing] first protected render in ${Math.round(performance.now() - startedAt)}ms`);
  }, [status]);

  if (status === 'loading') return <LoadingState />;
  if (status === 'error') return <ErrorState title="Autentikasi belum terhubung" description="Sesi belum dapat diperiksa. Periksa koneksi lalu coba lagi." onRetry={() => { setStatus('loading'); setRetryKey((value) => value + 1); }} />;
  if (!user) return <LoadingState label="Mengalihkan ke halaman masuk" />;
  return <AuthUserContext.Provider value={user}>{children}</AuthUserContext.Provider>;
}
