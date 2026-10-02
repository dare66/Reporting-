import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Api } from '../../core/api.service';
import { ago } from '../../core/format';
import { AppNotification, Envelope } from '../../core/models';
import { notificationIcon } from '../../core/notifications';
import { Icon } from '../../shared/icon';

@Component({
  selector: 'app-notifications',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  templateUrl: './notifications.html',
  styleUrl: './notifications.scss',
})
export class Notifications implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly items = signal<AppNotification[]>([]);
  ago = ago;
  readonly groups = computed(() => {
    const today = new Date().toDateString();
    const g: Record<string, AppNotification[]> = {};
    for (const n of this.items()) {
      const k = new Date(n.created_at).toDateString() === today ? 'Today' : 'Earlier';
      (g[k] ??= []).push(n);
    }
    return Object.entries(g).map(([label, items]) => ({ label, items }));
  });
  ngOnInit() {
    this.load();
  }
  async load() {
    this.items.set((await this.api.get<Envelope<AppNotification[]>>('/notifications')).data);
  }
  async readAll() {
    await this.api.post('/notifications/read-all');
    this.load();
  }
  async open(n: AppNotification) {
    if (!n.read_at) await this.api.post(`/notifications/${n.id}/read`);
    if (n.link) this.router.navigateByUrl(n.link);
    else this.load();
  }
  readonly icon = notificationIcon;
}
