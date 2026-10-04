import { Injectable, effect, inject } from '@angular/core';
import { Api } from './api.service';
import { Auth, User } from './auth.service';
import { setDateFormat } from './format';
import { Envelope, Preferences as Prefs } from './models';
import { Theme } from './theme.service';

/**
 * A person's preferences live on their profile, so they follow them to any
 * device. This applies them when the profile loads and saves changes back.
 */
@Injectable({ providedIn: 'root' })
export class Preferences {
  private api = inject(Api);
  private auth = inject(Auth);
  private theme = inject(Theme);

  constructor() {
    effect(() => {
      const p = this.auth.user()?.preferences;
      if (!p) return;
      if (p.theme && p.theme !== this.theme.mode()) this.theme.setMode(p.theme);
      if (p.accent && p.accent !== this.theme.accent()) this.theme.setAccent(p.accent);
      setDateFormat(p.date_format);
    });
  }

  get current(): Prefs {
    return this.auth.user()?.preferences ?? {};
  }

  /** Saves a change; the profile the API returns becomes the signed-in user. */
  async save(change: Prefs): Promise<void> {
    const user = (await this.api.patch<Envelope<User>>('/me/preferences', { preferences: change })).data;
    this.auth.user.set(user);
  }
}
