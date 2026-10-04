import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { API } from './api.service';
import { DataScope, Preferences } from './models';

export interface User {
  id: string;
  name: string;
  first_name: string;
  email: string;
  title?: string;
  roles: { key: string; name: string }[];
  permissions: string[];
  experience: 'executive' | 'analyst' | 'engineer' | 'admin';
  organisation: { id: string; name: string; currency: string; timezone: string; branding: { accent?: string } };
  data_scope?: DataScope | null;
  preferences: Preferences;
  mfa_enabled: boolean;
  department?: string;
  team?: string;
  /** An administrator set or reset the password; a new one must be chosen first. */
  must_change_password: boolean;
  /** What the organisation's security policy asks of this account. */
  security?: { mfa_required: boolean; password_min_length: number };
}

/** Why the account is held on the settings page until it complies with policy. */
export type Hold = 'password' | 'mfa' | null;

interface TokenPair {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  token_type: string;
}

interface SignedIn extends TokenPair {
  user: User;
}

/** Login answers with a session, or asks for the second factor first. */
type LoginResponse = SignedIn | { mfa_required: true; mfa_token: string };

const REFRESH_KEY = 'aixbi.refresh';

/**
 * Access token lives in memory only; the rotating refresh token is persisted
 * so a reload restores the session. Refresh tokens are single-use server-side
 * (reuse revokes the whole family).
 */
@Injectable({ providedIn: 'root' })
export class Auth {
  private http = inject(HttpClient);
  private router = inject(Router);
  readonly user = signal<User | null>(null);
  readonly accessToken = signal<string | null>(null);
  readonly isAuthenticated = computed(() => !!this.user());
  readonly hold = computed<Hold>(() => {
    const u = this.user();
    if (!u) return null;
    if (u.must_change_password) return 'password';
    return u.security?.mfa_required && !u.mfa_enabled ? 'mfa' : null;
  });
  private refreshing: Promise<boolean> | null = null;

  can(permission: string): boolean {
    const p = this.user()?.permissions ?? [];
    return p.includes('*') || p.includes(permission);
  }
  canAny(...permissions: string[]): boolean {
    return permissions.some((p) => this.can(p));
  }
  get isExecutive(): boolean {
    return this.user()?.experience === 'executive';
  }

  async login(email: string, password: string): Promise<{ mfa_token?: string }> {
    const res = await firstValueFrom(this.http.post<LoginResponse>(`${API}/auth/login`, { email, password }));
    if ('mfa_required' in res) return { mfa_token: res.mfa_token };
    this.accept(res);
    return {};
  }

  async verifyMfa(mfa_token: string, code: string): Promise<void> {
    this.accept(await firstValueFrom(this.http.post<SignedIn>(`${API}/auth/mfa/verify`, { mfa_token, code })));
  }

  /** Restores the session from a stored refresh token (called on app start). */
  async restore(): Promise<boolean> {
    if (!this.storedRefresh()) return false;
    return this.refresh();
  }

  refresh(): Promise<boolean> {
    this.refreshing ??= (async () => {
      const token = this.storedRefresh();
      if (!token) return false;
      try {
        const res = await firstValueFrom(this.http.post<TokenPair>(`${API}/auth/refresh`, { refresh_token: token }));
        this.storeRefresh(res.refresh_token);
        this.accessToken.set(res.access_token);
        if (!this.user()) await this.reloadProfile();
        return true;
      } catch {
        this.clear();
        return false;
      } finally {
        this.refreshing = null;
      }
    })();
    return this.refreshing;
  }

  async logout(): Promise<void> {
    const token = this.storedRefresh();
    try {
      if (token) await firstValueFrom(this.http.post(`${API}/auth/logout`, { refresh_token: token }));
    } catch {
      /* best effort */
    }
    this.clear();
    this.router.navigateByUrl('/');
  }

  async reloadProfile(): Promise<void> {
    this.user.set((await firstValueFrom(this.http.get<{ data: User }>(`${API}/me`))).data);
  }

  /** The stored refresh token, so a password change can keep this session signed in. */
  currentRefreshToken(): string | null {
    return this.storedRefresh();
  }

  private accept(res: SignedIn): void {
    this.accessToken.set(res.access_token);
    this.storeRefresh(res.refresh_token);
    this.user.set(res.user);
  }

  private clear(): void {
    this.accessToken.set(null);
    this.user.set(null);
    try {
      localStorage.removeItem(REFRESH_KEY);
    } catch {
      /* storage unavailable */
    }
  }

  private storedRefresh(): string | null {
    try {
      return localStorage.getItem(REFRESH_KEY);
    } catch {
      return null;
    }
  }
  private storeRefresh(token: string): void {
    try {
      localStorage.setItem(REFRESH_KEY, token);
    } catch {
      /* storage unavailable: session lasts for this tab */
    }
  }
}
