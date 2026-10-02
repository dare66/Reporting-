import { Injectable, signal } from '@angular/core';

export type ThemeMode = 'dark' | 'light' | 'system';
export const ACCENTS = [
  'executive',
  'financial',
  'government',
  'healthcare',
  'technology',
  'operations',
  'minimal',
] as const;

@Injectable({ providedIn: 'root' })
export class Theme {
  readonly mode = signal<ThemeMode>(this.read('aixbi.theme', 'dark') as ThemeMode);
  readonly accent = signal<string>(this.read('aixbi.accent', 'executive'));
  /** Bumps whenever resolved colours change so canvases (charts, globe) can re-read tokens. */
  readonly version = signal(0);

  constructor() {
    this.apply();
    matchMedia('(prefers-color-scheme: light)').addEventListener(
      'change',
      () => this.mode() === 'system' && this.apply(),
    );
  }

  setMode(m: ThemeMode) {
    this.mode.set(m);
    this.write('aixbi.theme', m);
    this.apply();
  }
  setAccent(a: string) {
    this.accent.set(a);
    this.write('aixbi.accent', a);
    this.apply();
  }
  toggle() {
    this.setMode(this.resolved() === 'dark' ? 'light' : 'dark');
  }
  resolved(): 'dark' | 'light' {
    const m = this.mode();
    return m === 'system' ? (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark') : m;
  }

  /** Reads a CSS custom property's resolved value. */
  token(name: string): string {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }
  series(): string[] {
    return [1, 2, 3, 4, 5, 6, 7, 8].map((i) => this.token(`--series-${i}`));
  }

  private apply() {
    const root = document.documentElement;
    root.dataset['theme'] = this.resolved();
    if (this.accent() === 'executive') delete root.dataset['accent'];
    else root.dataset['accent'] = this.accent();
    this.version.update((v) => v + 1);
  }
  private read(k: string, d: string) {
    try {
      return localStorage.getItem(k) ?? d;
    } catch {
      return d;
    }
  }
  private write(k: string, v: string) {
    try {
      localStorage.setItem(k, v);
    } catch {
      /* per-viewer convenience only */
    }
  }
}
