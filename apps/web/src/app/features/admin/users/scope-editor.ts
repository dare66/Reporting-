import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api } from '../../../core/api.service';
import { DataScope, Envelope } from '../../../core/models';

/**
 * Edits a person's data scope: all data, or only the listed countries. The
 * scope is enforced by row-level security on every query, export and AI answer.
 */
@Component({
  selector: 'app-scope-editor',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule],
  template: `
    <div class="seg" role="radiogroup" aria-label="Data access">
      <button
        type="button"
        role="radio"
        [attr.aria-checked]="!limited()"
        [class.on]="!limited()"
        (click)="setLimited(false)"
      >
        All data
      </button>
      <button
        type="button"
        role="radio"
        [attr.aria-checked]="limited()"
        [class.on]="limited()"
        (click)="setLimited(true)"
      >
        Selected countries
      </button>
    </div>
    @if (limited()) {
      <div class="chips" aria-live="polite">
        @for (c of codes(); track c) {
          <span class="code"
            >{{ c }}<button type="button" (click)="remove(c)" [attr.aria-label]="'Remove ' + c">×</button></span
          >
        } @empty {
          <span class="muted small">No countries yet: this person would see no rows.</span>
        }
      </div>
      <div class="add">
        <input
          class="input"
          [ngModel]="draft()"
          (ngModelChange)="draft.set($event.toUpperCase())"
          (keydown.enter)="$event.preventDefault(); add()"
          maxlength="2"
          placeholder="Country code, e.g. MY"
          aria-label="Add a country code"
          list="scope-countries"
        />
        <datalist id="scope-countries">
          @for (c of suggestions(); track c) {
            <option [value]="c"></option>
          }
        </datalist>
        <button type="button" class="btn sm" (click)="add()" [disabled]="!valid()">Add</button>
      </div>
    }
  `,
  styles: `
    :host {
      display: grid;
      gap: 10px;
    }
    .seg {
      justify-self: start;
    }
    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      min-height: 28px;
      align-items: center;
    }
    .code {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 4px 3px 10px;
      border-radius: 999px;
      background: var(--bg-3);
      font-size: 12.5px;
      font-weight: 600;
      letter-spacing: 0.04em;
    }
    .code button {
      border: 0;
      background: transparent;
      color: var(--ink-3);
      cursor: pointer;
      width: 20px;
      height: 20px;
      border-radius: 50%;
    }
    .code button:hover {
      background: var(--bg-2);
      color: var(--ink-1);
    }
    .add {
      display: flex;
      gap: 8px;
    }
    .add .input {
      max-width: 220px;
    }
    .small {
      font-size: 12px;
    }
  `,
})
export class ScopeEditor implements OnInit {
  private api = inject(Api);
  readonly scope = input<DataScope | null>(null);
  readonly scopeChange = output<DataScope | null>();

  readonly limited = signal(false);
  readonly codes = signal<string[]>([]);
  readonly draft = signal('');
  readonly suggestions = signal<string[]>([]);
  readonly valid = computed(() => /^[A-Z]{2}$/.test(this.draft()) && !this.codes().includes(this.draft()));

  async ngOnInit() {
    const codes = this.scope()?.country_codes;
    this.limited.set(Array.isArray(codes));
    this.codes.set(codes ?? []);
    try {
      // Suggestions only; any two-letter code can be typed.
      const url = '/semantic-models/applications/dimensions/country_code/members';
      this.suggestions.set((await this.api.get<Envelope<string[]>>(url, { limit: 300 })).data.map(String));
    } catch {
      this.suggestions.set([]);
    }
  }

  setLimited(limited: boolean) {
    this.limited.set(limited);
    this.emit();
  }
  add() {
    if (!this.valid()) return;
    this.codes.update((c) => [...c, this.draft()].sort());
    this.draft.set('');
    this.emit();
  }
  remove(code: string) {
    this.codes.update((c) => c.filter((x) => x !== code));
    this.emit();
  }
  private emit() {
    this.scopeChange.emit(this.limited() ? { country_codes: this.codes() } : null);
  }
}
