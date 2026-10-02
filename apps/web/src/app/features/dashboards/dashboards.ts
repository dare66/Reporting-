import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { Icon } from '../../shared/icon';
import { Empty, ErrorState } from '../../shared/states';

@Component({
  selector: 'app-dashboards',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, FormsModule, Empty, ErrorState],
  template: `
  <div class="page">
    <header class="page-head">
      <div><div class="eyebrow signal">Dashboards</div><h1>Living views of the business</h1>
        <p class="lede">Every dashboard reflows for desktop, tablet and phone. Ask the AI to build one, or start from a blank canvas.</p></div>
      <div class="row">
        @if (auth.can('ai.use')) { <a class="btn ai" routerLink="/ai" [queryParams]="{ q: 'Create a dashboard for university student application performance' }"><app-icon name="ai" [size]="16"/> Generate with AI</a> }
        @if (auth.can('dashboards.manage')) { <button class="btn signal" (click)="create()"><app-icon name="plus" [size]="16"/> New dashboard</button> }
      </div>
    </header>
    @if (error()) { <app-error [message]="error()!" (retry)="load()"/> }
    <div class="grid">
      @for (d of items(); track d.id) {
        <a class="card panel rise" [routerLink]="['/dashboards', d.id]">
          <div class="thumb" aria-hidden="true"><i class="k"></i><i class="k"></i><i class="k"></i><i class="c"></i><i class="s"></i></div>
          <div class="row"><b>{{ d.title }}</b><span class="spacer"></span>
            <button class="btn icon sm ghost fav" [class.on]="d.is_favourite" (click)="$event.preventDefault(); $event.stopPropagation(); fav(d)" [attr.aria-label]="d.is_favourite ? 'Remove favourite' : 'Add favourite'"><app-icon name="star" [size]="15"/></button></div>
          <p class="muted">{{ d.description }}</p>
          <div class="row small muted"><span>{{ d.widgets_count }} widgets</span>·<span>{{ d.owner }}</span>·<span>{{ ago(d.updated_at) }}</span>@if (d.is_home) { <span class="badge">Home</span> }@if (d.visibility === 'private') { <span class="badge">Private</span> }</div>
        </a>
      } @empty {
        @if (loaded()) { <app-empty eyebrow="Dashboards" title="Build your first intelligence view" body="Ask the AI analyst to generate a dashboard from a sentence, or start blank." [action]="auth.can('dashboards.manage') ? 'New dashboard' : null" (act)="create()"/> }
      }
    </div>
  </div>`,
  styles: [`.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
    .card{padding:16px;display:grid;gap:8px;transition:transform .2s var(--ease),border-color .2s}.card:hover{transform:translateY(-2px);border-color:var(--line-2)}
    .thumb{height:110px;border-radius:var(--r-md);background:var(--bg-0);display:grid;grid-template-columns:repeat(3,1fr);grid-template-rows:26px 1fr;gap:6px;padding:10px;margin-bottom:6px}
    .thumb i{border-radius:5px;background:var(--bg-3)}.thumb .c{grid-column:1/3;background:linear-gradient(180deg,transparent 40%,color-mix(in srgb,var(--series-1) 25%,transparent))}
    .thumb .s{background:color-mix(in srgb,var(--accent) 18%,var(--bg-3))}
    .small{font-size:12px;gap:6px}.fav.on{color:var(--accent)}`],
})
export class Dashboards implements OnInit {
  private api = inject(Api);
  private router = inject(Router);
  readonly auth = inject(Auth);
  readonly items = signal<any[]>([]);
  readonly loaded = signal(false);
  readonly error = signal<string | null>(null);
  ago = ago;

  ngOnInit() { this.load(); }
  async load() {
    try { this.items.set((await this.api.get('/dashboards')).data); } catch (e) { this.error.set(errorMessage(e)); } finally { this.loaded.set(true); }
  }
  async create() {
    const d = (await this.api.post('/dashboards', { title: 'Untitled dashboard', visibility: 'private', sections: [{ key: 'main', label: 'Overview' }] })).data;
    this.router.navigate(['/dashboards', d.id], { queryParams: { edit: 1 } });
  }
  async fav(d: any) {
    const r = await this.api.post('/bookmarks/toggle', { resource_type: 'dashboard', resource_id: d.id });
    this.items.update(xs => xs.map(x => (x.id === d.id ? { ...x, is_favourite: r.bookmarked } : x)));
  }
}
