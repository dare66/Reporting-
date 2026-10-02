import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Dashboard, DashboardSummary, Envelope } from '../../core/models';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { Icon } from '../../shared/icon';
import { Empty, ErrorState } from '../../shared/states';

@Component({
  selector: 'app-dashboards',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, FormsModule, Empty, ErrorState],
  templateUrl: './dashboards.html',
  styleUrl: './dashboards.scss',
})
export class Dashboards implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly auth = inject(Auth);
  readonly items = signal<DashboardSummary[]>([]);
  readonly loaded = signal(false);
  readonly error = signal<string | null>(null);
  ago = ago;

  ngOnInit() {
    this.load();
  }
  async load() {
    try {
      this.items.set((await this.api.get<Envelope<DashboardSummary[]>>('/dashboards')).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.loaded.set(true);
    }
  }
  async create() {
    try {
      const body = {
        title: 'Untitled dashboard',
        visibility: 'private',
        sections: [{ key: 'main', label: 'Overview' }],
      };
      const d = (await this.api.post<Envelope<Dashboard>>('/dashboards', body)).data;
      this.router.navigate(['/dashboards', d.id], { queryParams: { edit: 1 } });
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
  async fav(d: DashboardSummary) {
    const body = { resource_type: 'dashboard', resource_id: d.id };
    const r = await this.api.post<{ bookmarked: boolean }>('/bookmarks/toggle', body);
    this.items.update((xs) => xs.map((x) => (x.id === d.id ? { ...x, is_favourite: r.bookmarked } : x)));
  }
}
