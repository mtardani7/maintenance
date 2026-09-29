'use client';

import { FormEvent, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { login } from '@/lib/auth';
import { ApiConfigurationError, ApiError } from '@/lib/api';

export default function LoginPage() {
  const router = useRouter();
  const params = useSearchParams();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setError(''); setBusy(true);
    try {
      await login(email, password);
      const basePath = process.env.NEXT_PUBLIC_BASE_PATH ?? '';
      const requestedPath = params.get('next');
      const destination = requestedPath?.startsWith(basePath) ? requestedPath.slice(basePath.length) || '/' : requestedPath || '/dashboard';
      router.replace(destination);
    }
    catch (reason) { setError(reason instanceof ApiConfigurationError ? reason.message : reason instanceof ApiError ? reason.message : 'Tidak dapat masuk saat ini.'); }
    finally { setBusy(false); }
  }

  return <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6"><div className="w-full max-w-md"><div className="mb-8 flex items-center justify-center"><img src={`${process.env.NEXT_PUBLIC_BASE_PATH ?? ''}/qa-logo.png`} alt="Maintenance System" className="mr-2 size-11 object-contain" /><span className="text-lg font-semibold text-slate-950">Maintenance System</span></div><section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h1 className="text-xl font-semibold text-slate-950">Masuk</h1><p className="mt-1 text-sm text-slate-500">Akses ruang kerja pemeliharaan Anda.</p>{error && <div className="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" role="alert">{error}</div>}<form className="mt-6 space-y-4" onSubmit={handleSubmit}><label className="block text-sm font-medium text-slate-900">Email<input className="mt-1.5 h-11 w-full rounded-lg border border-slate-200 bg-transparent px-3 outline-none focus:border-emerald-500" type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="email" placeholder="anda@perusahaan.com" required /></label><label className="block text-sm font-medium text-slate-900">Kata sandi<input className="mt-1.5 h-11 w-full rounded-lg border border-slate-200 bg-transparent px-3 outline-none focus:border-emerald-500" type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" placeholder="••••••••" required /></label><button className="h-11 w-full rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-not-allowed disabled:opacity-60" type="submit" disabled={busy}>{busy ? 'Sedang masuk...' : 'Masuk'}</button></form></section></div></main>;
}
