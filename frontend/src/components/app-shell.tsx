'use client';

import type { ReactNode } from 'react';
import { useState } from 'react';
import { AuthBoundary } from './auth-boundary';
import { Header } from './header';
import { Navigation } from './navigation';

export function AppShell({ children }: { children: ReactNode }) {
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  return <AuthBoundary><div className={`app-frame${sidebarCollapsed ? ' app-frame--sidebar-collapsed' : ''}`}><Navigation collapsed={sidebarCollapsed} onCollapsedChange={setSidebarCollapsed} /><div className="content-frame"><Header /><main className="main-content">{children}</main></div></div></AuthBoundary>;
}
