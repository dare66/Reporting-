import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../../core/api.service';
import { Auth } from '../../../core/auth.service';
import { Envelope, SecurityPolicy } from '../../../core/models';
import { Icon } from '../../../shared/icon';

const MFA_MODES: { key: SecurityPolicy['require_mfa']; label: string; hint: string }[] = [
  { key: 'none', label: 'Optional', hint: 'People choose for themselves.' },
  { key: 'admins', label: 'Administrators', hint: 'Anyone who can manage people, roles or the organisation.' },
  { key: 'all', label: 'Everyone', hint: 'Every account, at their next request.' },
];

/** The organisation's sign-in rules, enforced by the API on every request. */
@Component({
  selector: 'app-security-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon],
  templateUrl: './security-tab.html',
  styleUrl: './security-tab.scss',
})
export class SecurityTab implements OnInit {
  private api = inject(Api);
  readonly auth = inject(Auth);
  readonly modes = MFA_MODES;

  readonly saved = signal<SecurityPolicy | null>(null);
  readonly minLength = signal(12);
  readonly mfa = signal<SecurityPolicy['require_mfa']>('none');
  readonly domains = signal<string[]>([]);
  readonly domainDraft = signal('');
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly saving = signal(false);

  readonly dirty = computed(() => {
    const s = this.saved();
    return (
      !!s &&
      (s.password_min_length !== this.minLength() ||
        s.require_mfa !== this.mfa() ||
        s.allowed_email_domains.join() !== this.domains().join())
    );
  });
  readonly domainValid = computed(() => /^([a-z0-9-]+\.)+[a-z]{2,}$/i.test(this.domainDraft().trim()));
  /** Requiring MFA for yourself before enrolling would lock this session out; the API refuses it too. */
  readonly selfBlocked = computed(() => this.mfa() !== 'none' && !this.auth.user()?.mfa_enabled);

  async ngOnInit() {
    try {
      this.apply((await this.api.get<Envelope<SecurityPolicy>>('/admin/security-policy')).data);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  private apply(p: SecurityPolicy) {
    this.saved.set(p);
    this.minLength.set(p.password_min_length);
    this.mfa.set(p.require_mfa);
    this.domains.set(p.allowed_email_domains);
  }

  addDomain() {
    const d = this.domainDraft().trim().toLowerCase();
    if (!this.domainValid() || this.domains().includes(d)) return;
    this.domains.update((x) => [...x, d]);
    this.domainDraft.set('');
  }
  removeDomain(d: string) {
    this.domains.update((x) => x.filter((y) => y !== d));
  }

  async save() {
    this.saving.set(true);
    this.error.set(null);
    this.notice.set(null);
    try {
      const body = {
        password_min_length: this.minLength(),
        require_mfa: this.mfa(),
        allowed_email_domains: this.domains(),
      };
      this.apply((await this.api.put<Envelope<SecurityPolicy>>('/admin/security-policy', body)).data);
      this.notice.set('Policy saved. It applies from each person’s next request.');
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.saving.set(false);
    }
  }
}
