import type { ReactNode } from 'react';
import { AppShell } from '@/components/app-shell';
import { MobileBottomNavigation } from '@/components/mobile-bottom-navigation';

export default function ProtectedLayout({ children }: { children: ReactNode }) {
  return <AppShell><>{children}<MobileBottomNavigation /></></AppShell>;
}
