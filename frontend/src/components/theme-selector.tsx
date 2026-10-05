'use client';

import { useEffect, useState } from 'react';
import { Check, Monitor, Moon, Sun, SunMoon } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

type ThemeMode = 'system' | 'light' | 'dark';
const THEME_STORAGE_KEY = 'maintenance-theme';
const choices: { value: ThemeMode; label: string; Icon: LucideIcon }[] = [
  { value: 'system', label: 'Ikuti sistem', Icon: Monitor },
  { value: 'light', label: 'Terang', Icon: Sun },
  { value: 'dark', label: 'Gelap', Icon: Moon },
];

export function ThemeSelector() {
  const [mode, setMode] = useState<ThemeMode>('system');
  const [systemDark, setSystemDark] = useState(false);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const updateSystemTheme = () => setSystemDark(media.matches);
    updateSystemTheme();
    media.addEventListener('change', updateSystemTheme);

    try {
      const storedMode = localStorage.getItem(THEME_STORAGE_KEY);
      if (storedMode === 'system' || storedMode === 'light' || storedMode === 'dark') setMode(storedMode);
    } catch { /* The system preference remains available when storage is blocked. */ }

    return () => media.removeEventListener('change', updateSystemTheme);
  }, []);

  useEffect(() => {
    const resolvedTheme = mode === 'system' ? (systemDark ? 'dark' : 'light') : mode;
    const root = document.documentElement;
    root.dataset.theme = resolvedTheme;
    root.style.colorScheme = resolvedTheme;
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', resolvedTheme === 'dark' ? '#0b1120' : '#f4f5f7');
  }, [mode, systemDark]);

  useEffect(() => {
    if (!open) return;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [open]);

  function chooseTheme(nextMode: ThemeMode) {
    setMode(nextMode);
    setOpen(false);
    try { localStorage.setItem(THEME_STORAGE_KEY, nextMode); } catch { /* The selected theme still applies for this session. */ }
  }

  const ActiveIcon = mode === 'system' ? SunMoon : mode === 'dark' ? Moon : Sun;
  const modeLabel = choices.find((choice) => choice.value === mode)?.label ?? 'Ikuti sistem';

  return (
    <div className="theme-menu">
      <button className="theme-button" type="button" aria-label={`Tema ${modeLabel}`} aria-expanded={open} aria-haspopup="menu" title={`Tema: ${modeLabel}`} onClick={() => setOpen((value) => !value)}>
        <ActiveIcon aria-hidden="true" />
      </button>
      {open && <div className="theme-menu__popover" role="menu" aria-label="Pilih tema">
        <strong>Tema tampilan</strong>
        {choices.map(({ value, label, Icon }) => <button key={value} type="button" role="menuitemradio" aria-checked={mode === value} onClick={() => chooseTheme(value)}>
          <Icon aria-hidden="true" /><span>{label}</span>{mode === value && <Check className="theme-menu__check" aria-hidden="true" />}
        </button>)}
      </div>}
    </div>
  );
}
