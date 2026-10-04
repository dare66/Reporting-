import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
import { Icon } from '../../../shared/icon';

/** A password shown exactly once, with copy; it is never retrievable again. */
@Component({
  selector: 'app-one-time-secret',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div class="secret" role="status">
      <app-icon name="key" [size]="18" />
      <div class="body">
        <b>{{ heading() }}</b>
        <code class="mono">{{ value() }}</code>
        <span class="muted small"
          >Shown once. Share it securely; they will be asked to choose their own password when they sign in.</span
        >
      </div>
      <button class="btn sm" (click)="copy()">{{ copied() ? 'Copied' : 'Copy' }}</button>
    </div>
  `,
  styles: `
    .secret {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      padding: 14px;
      border-radius: var(--r-md);
      border: 1px solid color-mix(in srgb, var(--accent) 45%, transparent);
      background: color-mix(in srgb, var(--accent) 8%, transparent);
    }
    .body {
      display: grid;
      gap: 4px;
      flex: 1;
    }
    code {
      font-size: 15px;
      letter-spacing: 0.04em;
      user-select: all;
    }
    .small {
      font-size: 12px;
    }
  `,
})
export class OneTimeSecret {
  readonly value = input.required<string>();
  readonly heading = input('One-time password');
  readonly copied = signal(false);

  async copy() {
    try {
      await navigator.clipboard.writeText(this.value());
      this.copied.set(true);
    } catch {
      this.copied.set(false); // clipboard blocked: the value stays selectable
    }
  }
}

const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

/** A strong initial password in the same shape the API issues for resets (xxxxx-xxxxx-xxxxx-xxxxx). */
export function generatePassword(): string {
  const bytes = crypto.getRandomValues(new Uint32Array(20));
  const chars = Array.from(bytes, (b) => ALPHABET[b % ALPHABET.length]).join('');
  return chars.match(/.{5}/g)?.join('-') ?? chars;
}
