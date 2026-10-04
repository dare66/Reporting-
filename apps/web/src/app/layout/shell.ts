import { CdkTrapFocus } from '@angular/cdk/a11y';
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  HostListener,
  OnDestroy,
  OnInit,
  computed,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter } from 'rxjs';
import { Api } from '../core/api.service';
import { Auth } from '../core/auth.service';
import { AppNotification, SearchResult } from '../core/models';
import { notificationIcon } from '../core/notifications';
import { ago, setCurrencySymbol } from '../core/format';
import { Preferences } from '../core/preferences.service';
import { Theme } from '../core/theme.service';
import { Icon } from '../shared/icon';
import { Scrim } from '../shared/scrim';

interface NavItem {
  path: string;
  label: string;
  icon: string;
  perm?: string[];
  group: 'primary' | 'intelligence' | 'platform' | 'account';
}

const NAV: NavItem[] = [
  { path: '/home', label: 'Command Centre', icon: 'home', group: 'primary' },
  { path: '/ai', label: 'AI Analyst', icon: 'ai', perm: ['ai.use'], group: 'primary' },
  { path: '/insights', label: 'Insights', icon: 'pulse', group: 'primary' },
  { path: '/dashboards', label: 'Dashboards', icon: 'dashboard', perm: ['dashboards.view'], group: 'primary' },
  { path: '/reports', label: 'Reports', icon: 'report', perm: ['reports.view'], group: 'primary' },
  { path: '/explore', label: 'Explore', icon: 'explore', perm: ['query.run'], group: 'intelligence' },
  { path: '/forecast', label: 'Forecast', icon: 'forecast', perm: ['analytics.advanced'], group: 'intelligence' },
  { path: '/alerts', label: 'Alerts', icon: 'alert', perm: ['alerts.view'], group: 'intelligence' },
  { path: '/data', label: 'Data', icon: 'data', perm: ['data.view'], group: 'platform' },
  { path: '/semantic', label: 'Semantic Model', icon: 'semantic', perm: ['semantic.view'], group: 'platform' },
  { path: '/metrics', label: 'Metric Store', icon: 'target', perm: ['semantic.view'], group: 'platform' },
  {
    path: '/governance',
    label: 'Governance',
    icon: 'governance',
    perm: ['governance.view', 'audit.view'],
    group: 'platform',
  },
  {
    path: '/admin',
    label: 'Administration',
    icon: 'admin',
    perm: ['admin.users', 'admin.system', 'admin.org'],
    group: 'account',
  },
  { path: '/settings', label: 'Settings', icon: 'user', group: 'account' },
];

const GROUP_LABELS: Record<NavItem['group'], string> = {
  primary: '',
  intelligence: 'Intelligence',
  platform: 'Platform',
  account: 'Account',
};

/** Executives get a focused surface: platform plumbing stays out of their way. */
const EXECUTIVE_HIDDEN = new Set(['/semantic', '/data']);

@Component({
  selector: 'app-shell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CdkTrapFocus, Scrim, RouterOutlet, RouterLink, RouterLinkActive, FormsModule, Icon],
  templateUrl: './shell.html',
  styleUrl: './shell.scss',
})
export class Shell implements OnInit, OnDestroy {
  readonly auth = inject(Auth);
  readonly theme = inject(Theme);
  /** Applies the signed-in person's saved preferences (theme, accent, date order). */
  private preferences = inject(Preferences);
  private api = inject(Api);
  private router = inject(Router);

  readonly user = this.auth.user;
  readonly nav = computed(() => {
    const exec = this.user()?.experience === 'executive';
    return NAV.filter((n) => (!n.perm || this.auth.canAny(...n.perm)) && !(exec && EXECUTIVE_HIDDEN.has(n.path)));
  });
  readonly groups = computed(() =>
    (['primary', 'intelligence', 'platform', 'account'] as const)
      .map((g) => ({ g, label: GROUP_LABELS[g], items: this.nav().filter((n) => n.group === g) }))
      .filter((x) => x.items.length),
  );
  readonly mobileTabs = computed(() =>
    this.nav().filter((n) => ['/home', '/insights', '/dashboards', '/reports'].includes(n.path)),
  );
  readonly moreOpen = signal(false);

  readonly query = signal('');
  readonly results = signal<SearchResult[]>([]);
  readonly askAi = signal(false);
  readonly searchOpen = signal(false);
  private searchTimer?: ReturnType<typeof setTimeout>;
  private searchInput = viewChild<ElementRef<HTMLInputElement>>('searchInput');

  readonly notifications = signal<AppNotification[]>([]);
  readonly unread = signal(0);
  readonly bellOpen = signal(false);
  readonly online = signal(navigator.onLine);
  private stream?: AbortController;
  ago = ago;

  ngOnInit() {
    setCurrencySymbol(this.user()?.organisation.currency ?? 'MYR');
    this.loadNotifications();
    this.listen();
    this.router.events.pipe(filter((e) => e instanceof NavigationEnd)).subscribe(() => {
      this.moreOpen.set(false);
      this.searchOpen.set(false);
      this.bellOpen.set(false);
    });
    addEventListener('online', () => this.online.set(true));
    addEventListener('offline', () => this.online.set(false));
  }

  ngOnDestroy() {
    this.stream?.abort();
  }

  @HostListener('document:keydown', ['$event'])
  onKey(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      this.searchOpen.set(true);
      setTimeout(() => this.searchInput()?.nativeElement.focus());
    }
    if (e.key === 'Escape') {
      this.searchOpen.set(false);
      this.bellOpen.set(false);
      this.moreOpen.set(false);
    }
  }

  onSearch(q: string) {
    this.query.set(q);
    clearTimeout(this.searchTimer);
    if (q.trim().length < 2) {
      this.results.set([]);
      this.askAi.set(false);
      return;
    }
    this.searchTimer = setTimeout(async () => {
      try {
        const res = await this.api.get<{ data: SearchResult[]; ask_ai: boolean }>('/search', { q });
        this.results.set(res.data);
        this.askAi.set(res.ask_ai);
      } catch {
        this.results.set([]);
      }
    }, 180);
  }

  submitSearch() {
    const q = this.query().trim();
    if (!q) return;
    if (this.askAi() || !this.results().length) this.ask(q);
    else this.router.navigateByUrl(this.results()[0].link);
  }

  ask(q: string) {
    this.searchOpen.set(false);
    this.query.set('');
    this.router.navigate(['/ai'], { queryParams: { q } });
  }

  async loadNotifications() {
    try {
      const res = await this.api.get<{ data: AppNotification[]; unread: number }>('/notifications');
      this.notifications.set(res.data.slice(0, 12));
      this.unread.set(res.unread);
    } catch {
      /* bell stays quiet if unavailable */
    }
  }

  async openNotification(n: AppNotification) {
    this.bellOpen.set(false);
    if (!n.read_at) {
      this.api.post(`/notifications/${n.id}/read`).then(() => this.loadNotifications());
    }
    if (n.link) this.router.navigateByUrl(n.link);
  }

  async readAll() {
    await this.api.post('/notifications/read-all');
    this.loadNotifications();
  }

  /** Live notifications over SSE (fetch-based so the bearer token is sent in a header). */
  private async listen() {
    this.stream = new AbortController();
    for (let attempt = 0; attempt < 50 && !this.stream.signal.aborted; attempt++) {
      try {
        const res = await fetch('/api/v1/notifications/stream', {
          headers: { Authorization: `Bearer ${this.auth.accessToken()}` },
          signal: this.stream.signal,
        });
        if (res.status === 401) {
          await this.auth.refresh();
          continue;
        }
        if (!res.body) throw new Error('Notification stream has no body.');
        const reader = res.body.getReader();
        const dec = new TextDecoder();
        for (;;) {
          const { value, done } = await reader.read();
          if (done) break;
          if (dec.decode(value).includes('event: notification')) this.loadNotifications();
        }
      } catch {
        if (this.stream.signal.aborted) return;
      }
      await new Promise((r) => setTimeout(r, 3000));
    }
  }

  openLink(link: string) {
    this.router.navigateByUrl(link);
  }

  initials(name?: string) {
    return (name ?? '?')
      .split(' ')
      .map((p) => p[0])
      .slice(0, 2)
      .join('');
  }
  readonly icon = notificationIcon;
}
