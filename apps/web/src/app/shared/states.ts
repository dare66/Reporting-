import { ChangeDetectionStrategy, Component, input, output, signal } from '@angular/core';
import { Icon } from './icon';

/** Contextual loading: names the work being done instead of "Loading…". */
@Component({
  selector: 'app-working',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [],
  template: `
    <div class="working" role="status" aria-live="polite">
      <div class="orbit"><span></span><span></span><span></span></div>
      <div>
        <div class="title">{{ title() }}</div>
        <div class="steps">
          @for (s of steps(); track s; let i = $index) {
            <span [style.animation-delay]="i * 0.9 + 's'">{{ s }}</span>@if (i < steps().length - 1) {<i>→</i>}
          }
        </div>
      </div>
    </div>`,
  styles: [`
    .working { display:flex; align-items:center; gap:16px; padding:28px; }
    .title { font-weight:600; margin-bottom:4px; }
    .steps { display:flex; flex-wrap:wrap; gap:6px; color:var(--ink-3); font-size:12.5px; }
    .steps span { animation: lit 3.6s infinite; } .steps i { font-style:normal; opacity:.4; }
    @keyframes lit { 0%,100% { color: var(--ink-3);} 20% { color: var(--ink-1);} }
    .orbit { position:relative; width:36px; height:36px; flex:none; }
    .orbit span { position:absolute; inset:0; border-radius:50%; border:1.5px solid transparent; border-top-color:var(--accent); animation:spin 1.2s linear infinite; }
    .orbit span:nth-child(2){ inset:6px; border-top-color:var(--ai); animation-duration:1.8s; animation-direction:reverse; }
    .orbit span:nth-child(3){ inset:12px; border-top-color:var(--series-1); animation-duration:2.4s; }
    @keyframes spin { to { transform: rotate(360deg);} }`],
})
export class Working {
  readonly title = input('Analysing data');
  readonly steps = input<string[]>(['Discovering patterns', 'Checking anomalies', 'Building insights']);
}

@Component({
  selector: 'app-empty',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div class="empty">
      <div class="art"><svg viewBox="0 0 120 80" width="120" height="80" aria-hidden="true">
        <g fill="none" stroke="currentColor" stroke-width="1.2" opacity=".5"><path d="M8 70h104"/><path d="M8 50h104" opacity=".4"/><path d="M8 30h104" opacity=".25"/></g>
        <path d="M10 62 32 48l18 8 22-24 18 10 20-18" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-dasharray="4 5"/>
        <circle cx="110" cy="24" r="4" fill="var(--accent)"/></svg></div>
      <div class="eyebrow signal">{{ eyebrow() }}</div>
      <h2>{{ title() }}</h2>
      <p class="secondary">{{ body() }}</p>
      @if (action()) { <button class="btn signal" (click)="act.emit()">{{ action() }} <app-icon name="arrowRight" [size]="16"/></button> }
    </div>`,
  styles: [`.empty{display:grid;justify-items:start;gap:12px;padding:40px;max-width:560px}.art{color:var(--ink-3)}h2{font-size:24px}`],
})
export class Empty {
  readonly eyebrow = input('Ready');
  readonly title = input.required<string>();
  readonly body = input('');
  readonly action = input<string | null>(null);
  readonly act = output<void>();
}

@Component({
  selector: 'app-error',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div class="err" role="alert">
      <div class="icon"><app-icon name="warning" [size]="20"/></div>
      <div class="body">
        <div class="t">{{ title() }}</div>
        <div class="secondary">{{ message() }}</div>
        @if (lastSuccess()) { <div class="muted small">Last successful update: {{ lastSuccess() }}</div> }
        <div class="row" style="margin-top:12px">
          <button class="btn sm" (click)="retry.emit()"><app-icon name="refresh" [size]="14"/> Retry</button>
          @if (details()) { <button class="btn sm ghost" (click)="open.set(!open())">Technical details</button> }
        </div>
        @if (open()) { <pre class="mono">{{ details() }}</pre> }
      </div>
    </div>`,
  styles: [`.err{display:flex;gap:14px;padding:20px;border-radius:var(--r-md);background:var(--neg-soft);border:1px solid color-mix(in srgb,var(--neg) 25%,transparent)}
    .icon{color:var(--neg)} .t{font-weight:650;text-transform:uppercase;letter-spacing:.06em;font-size:12px;margin-bottom:4px}
    .small{font-size:12px;margin-top:6px} pre{white-space:pre-wrap;margin:10px 0 0;color:var(--ink-3)}`],
})
export class ErrorState {
  readonly title = input("We couldn't refresh this data");
  readonly message = input('');
  readonly details = input<string | null>(null);
  readonly lastSuccess = input<string | null>(null);
  readonly retry = output<void>();
  readonly open = signal(false);
}
