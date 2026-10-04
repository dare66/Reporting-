import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth, User } from '../../core/auth.service';
import { ago, fmtDate } from '../../core/format';
import { Envelope, NotificationCategory, Preferences as Prefs, Session } from '../../core/models';
import { Preferences } from '../../core/preferences.service';
import { ACCENTS, Theme, ThemeMode } from '../../core/theme.service';
import { Icon } from '../../shared/icon';

type SectionKey = 'profile' | 'password' | 'mfa' | 'sessions' | 'notifications' | 'preferences' | 'access';
type Channel = 'email' | 'push';

const SECTIONS: { key: SectionKey; label: string; icon: string }[] = [
  { key: 'profile', label: 'Profile', icon: 'user' },
  { key: 'password', label: 'Password', icon: 'key' },
  { key: 'mfa', label: 'Two-factor', icon: 'lock' },
  { key: 'sessions', label: 'Devices', icon: 'mobile' },
  { key: 'notifications', label: 'Notifications', icon: 'bell' },
  { key: 'preferences', label: 'Preferences', icon: 'styles' },
  { key: 'access', label: 'Data access', icon: 'governance' },
];

const CATEGORIES: { key: NotificationCategory; label: string; hint: string }[] = [
  { key: 'alert', label: 'Alerts', hint: 'A threshold you or your team set is breached or recovers' },
  { key: 'anomaly', label: 'Anomalies', hint: 'Unusual movements detected in governed metrics' },
  { key: 'report', label: 'Reports', hint: 'Scheduled reports and exports are ready' },
  { key: 'mention', label: 'Mentions', hint: 'Someone mentions you in a comment' },
];

const RANGES: { key: NonNullable<Prefs['default_range']>; label: string }[] = [
  { key: 'last_7_days', label: '7 days' },
  { key: 'last_30_days', label: '30 days' },
  { key: 'this_month', label: 'Month to date' },
  { key: 'this_quarter', label: 'Quarter to date' },
  { key: 'year_to_date', label: 'Year to date' },
];

/** A person's own account: profile, sign-in security, devices, notifications and preferences. */
@Component({
  selector: 'app-settings',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon],
  templateUrl: './settings.html',
  styleUrl: './settings.scss',
})
export class Settings implements OnInit {
  readonly auth = inject(Auth);
  readonly theme = inject(Theme);
  private prefs = inject(Preferences);
  private api = inject(Api);
  private router = inject(Router);

  readonly sections = SECTIONS;
  readonly categories = CATEGORIES;
  readonly ranges = RANGES;
  readonly accents = ACCENTS;
  readonly modes: { key: ThemeMode; label: string }[] = [
    { key: 'dark', label: 'Dark' },
    { key: 'light', label: 'Light' },
    { key: 'system', label: 'Match system' },
  ];
  readonly dateFormats: { key: NonNullable<Prefs['date_format']>; label: string }[] = [
    { key: 'day_month', label: 'Day first' },
    { key: 'month_day', label: 'Month first' },
    { key: 'iso', label: 'ISO (year first)' },
  ];
  readonly user = this.auth.user;
  readonly hold = this.auth.hold;
  ago = ago;
  /** Today in the chosen format: a live example for the date setting. */
  today = () => {
    const d = new Date();
    return fmtDate(
      `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`,
    );
  };

  // Feedback per section, so a message appears where the action happened.
  readonly notice = signal<{ section: SectionKey; text: string; error?: boolean } | null>(null);

  // Profile
  readonly name = signal('');
  readonly title = signal('');
  readonly profileDirty = computed(
    () => this.name().trim() !== (this.user()?.name ?? '') || this.title().trim() !== (this.user()?.title ?? ''),
  );

  // Password
  readonly current = signal('');
  readonly next = signal('');
  readonly confirm = signal('');
  readonly minLength = computed(() => this.user()?.security?.password_min_length ?? 12);
  readonly strength = computed(() => {
    const p = this.next();
    const variety = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((r) => r.test(p)).length;
    const score = p.length < this.minLength() ? 0 : Math.min(3, Math.floor(p.length / 8) + (variety >= 3 ? 1 : 0));
    return { score, label: ['Too short', 'Fair', 'Strong', 'Very strong'][score] };
  });
  readonly passwordReady = computed(
    () => !!this.current() && this.next().length >= this.minLength() && this.next() === this.confirm(),
  );

  // Two-factor and devices
  readonly secret = signal<{ secret: string; uri: string } | null>(null);
  readonly code = signal('');
  readonly sessions = signal<Session[]>([]);
  readonly mfaRequired = computed(() => !!this.user()?.security?.mfa_required);

  roles = computed(() => (this.user()?.roles ?? []).map((r) => r.name).join(', '));

  ngOnInit() {
    this.name.set(this.user()?.name ?? '');
    this.title.set(this.user()?.title ?? '');
    this.loadSessions();
    const target = this.hold() === 'password' ? 'password' : this.hold() === 'mfa' ? 'mfa' : null;
    if (target) setTimeout(() => this.jump(target));
  }

  jump(key: SectionKey) {
    document.getElementById('s-' + key)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  private say(section: SectionKey, text: string, error = false) {
    this.notice.set({ section, text, error });
  }
  noticeFor(section: SectionKey) {
    const n = this.notice();
    return n?.section === section ? n : null;
  }

  // ── Profile ────────────────────────────────────────────────────────────────
  async saveProfile() {
    try {
      const u = (
        await this.api.patch<Envelope<User>>('/me', { name: this.name().trim(), title: this.title().trim() || null })
      ).data;
      this.auth.user.set(u);
      this.say('profile', 'Profile saved.');
    } catch (e) {
      this.say('profile', errorMessage(e), true);
    }
  }

  // ── Password ───────────────────────────────────────────────────────────────
  async changePassword() {
    try {
      const u = (
        await this.api.post<Envelope<User>>('/me/password', {
          current_password: this.current(),
          password: this.next(),
          password_confirmation: this.confirm(),
          refresh_token: this.auth.currentRefreshToken(),
        })
      ).data;
      const wasHeld = this.hold() === 'password';
      this.auth.user.set(u);
      this.current.set('');
      this.next.set('');
      this.confirm.set('');
      this.say('password', 'Password changed. Your other devices were signed out.');
      this.loadSessions();
      if (wasHeld && !this.hold()) this.router.navigateByUrl('/home');
    } catch (e) {
      this.say('password', errorMessage(e), true);
    }
  }

  // ── Two-factor ─────────────────────────────────────────────────────────────
  async setup() {
    try {
      const res = await this.api.post<{ secret: string; otpauth_uri: string }>('/me/mfa/setup');
      this.secret.set({ secret: res.secret, uri: res.otpauth_uri });
    } catch (e) {
      this.say('mfa', errorMessage(e), true);
    }
  }
  async enable() {
    try {
      await this.api.post('/me/mfa/enable', { code: this.code() });
      const wasHeld = this.hold() === 'mfa';
      this.secret.set(null);
      this.code.set('');
      await this.auth.reloadProfile();
      this.say('mfa', 'Two-factor authentication is on.');
      if (wasHeld && !this.hold()) this.router.navigateByUrl('/home');
    } catch (e) {
      this.say('mfa', errorMessage(e), true);
    }
  }
  async disable() {
    try {
      await this.api.delete('/me/mfa');
      await this.auth.reloadProfile();
      this.say('mfa', 'Two-factor authentication is off.');
    } catch (e) {
      this.say('mfa', errorMessage(e), true);
    }
  }

  // ── Devices ────────────────────────────────────────────────────────────────
  async loadSessions() {
    try {
      this.sessions.set((await this.api.get<Envelope<Session[]>>('/me/sessions')).data);
    } catch {
      this.sessions.set([]); // held accounts may not list devices yet
    }
  }
  async revoke(s: Session) {
    try {
      await this.api.delete(`/me/sessions/${s.id}`);
      await this.loadSessions();
      this.say('sessions', 'Device signed out.');
    } catch (e) {
      this.say('sessions', errorMessage(e), true);
    }
  }

  // ── Notifications & preferences (saved immediately) ───────────────────────
  channel(category: NotificationCategory, channel: Channel): boolean {
    return this.user()?.preferences.notifications?.[category]?.[channel] !== false;
  }
  async setChannel(category: NotificationCategory, channel: Channel, on: boolean) {
    await this.savePrefs('notifications', { notifications: { [category]: { [channel]: on } } });
  }
  async setMode(mode: ThemeMode) {
    this.theme.setMode(mode);
    await this.savePrefs('preferences', { theme: mode });
  }
  async setAccent(accent: string) {
    this.theme.setAccent(accent);
    await this.savePrefs('preferences', { accent });
  }
  async setPref(change: Prefs) {
    await this.savePrefs('preferences', change);
  }
  private async savePrefs(section: SectionKey, change: Prefs) {
    try {
      await this.prefs.save(change);
      this.say(section, 'Saved.');
    } catch (e) {
      this.say(section, errorMessage(e), true);
    }
  }
}
