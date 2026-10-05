import type { Metadata, Viewport } from 'next';
import { ServiceWorkerRegistration } from '@/components/service-worker-registration';
import './globals.css';

export const metadata: Metadata = {
  title: 'MIRA',
  description: 'Maintenance Improvement Report Analysis',
  applicationName: 'MIRA',
  icons: {
    icon: `${process.env.NEXT_PUBLIC_BASE_PATH ?? ''}/pwa-icon-192.png?v=2`,
    apple: `${process.env.NEXT_PUBLIC_BASE_PATH ?? ''}/pwa-icon-192.png?v=2`,
  },
};

export const viewport: Viewport = {
  themeColor: '#f4f5f7',
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const basePath = process.env.NEXT_PUBLIC_BASE_PATH ?? '';
  return <html lang="id" suppressHydrationWarning><head>
    <link rel="manifest" href={`${basePath}/manifest.webmanifest`} />
    <script dangerouslySetInnerHTML={{ __html: `(()=>{let mode='system';try{mode=localStorage.getItem('maintenance-theme')||'system'}catch{}const dark=mode==='dark'||(mode==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);const theme=dark?'dark':'light';document.documentElement.dataset.theme=theme;document.documentElement.style.colorScheme=theme;document.querySelector('meta[name="theme-color"]')?.setAttribute('content',dark?'#0b1120':'#f4f5f7')})()` }} />
  </head><body><ServiceWorkerRegistration />{children}</body></html>;
}
