import { ChangeDetectionStrategy, Component, ElementRef, NgZone, OnDestroy, afterNextRender, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Auth } from '../../core/auth.service';
import { errorMessage } from '../../core/api.service';
import { Icon } from '../../shared/icon';

const PERSONAS = [
  { email: 'ceo@northstar.demo', name: 'Mohammed Hakim', role: 'CEO', note: 'Executive experience' },
  { email: 'manager.asia@northstar.demo', name: 'Wei Ling Chen', role: 'Head of Asia Markets', note: 'Row-level security: 6 markets' },
  { email: 'analyst@northstar.demo', name: 'Priya Nair', role: 'Senior Analyst', note: 'Explore, build, SQL provenance' },
  { email: 'engineer@northstar.demo', name: 'Daniel Lim', role: 'Data Engineer', note: 'Connectors, semantic layer' },
  { email: 'admin@northstar.demo', name: 'Sarah Wong', role: 'Tenant Admin', note: 'Users, roles, health' },
];

@Component({
  selector: 'app-landing',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon],
  templateUrl: './landing.html',
  styleUrl: './landing.scss',
})
export class Landing implements OnDestroy {
  private auth = inject(Auth);
  private router = inject(Router);
  private zone = inject(NgZone);
  private canvas = viewChild.required<ElementRef<HTMLCanvasElement>>('field');

  readonly personas = PERSONAS;
  readonly email = signal('ceo@northstar.demo');
  readonly password = signal('Demo@2026!');
  readonly code = signal('');
  readonly mfaToken = signal<string | null>(null);
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly panel = signal(false);
  private raf = 0;

  constructor() {
    afterNextRender(async () => {
      if (await this.auth.restore()) { this.router.navigateByUrl('/home'); return; }
      this.zone.runOutsideAngular(() => this.animate());
    });
  }

  ngOnDestroy() { cancelAnimationFrame(this.raf); }

  pick(p: (typeof PERSONAS)[number]) { this.email.set(p.email); this.password.set('Demo@2026!'); this.panel.set(true); }

  async submit() {
    this.busy.set(true);
    this.error.set(null);
    try {
      if (this.mfaToken()) {
        await this.auth.verifyMfa(this.mfaToken()!, this.code());
      } else {
        const r = await this.auth.login(this.email(), this.password());
        if (r.mfa_token) { this.mfaToken.set(r.mfa_token); return; }
      }
      this.router.navigateByUrl('/home');
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  /**
   * Data field: particles enter as raw data on the left, converge through the
   * AI lens, and resolve into ordered insight streams on the right.
   * Throttled to the display, paused when hidden, static under reduced motion.
   */
  private animate() {
    const cv = this.canvas().nativeElement;
    const ctx = cv.getContext('2d')!;
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    let w = 0, h = 0, dpr = Math.min(devicePixelRatio, 2);
    const resize = () => { w = cv.clientWidth; h = cv.clientHeight; cv.width = w * dpr; cv.height = h * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0); };
    resize();
    addEventListener('resize', resize);
    const N = Math.min(420, Math.floor((w * h) / 2600));
    const lanes = 5;
    const colors = ['#3987e5', '#199e70', '#9085e9', '#e8b04b', '#d55181'];
    type P = { x: number; y: number; v: number; lane: number; seed: number; size: number };
    const ps: P[] = Array.from({ length: N }, () => ({ x: Math.random() * w, y: Math.random() * h, v: 0.4 + Math.random() * 1.1, lane: Math.floor(Math.random() * lanes), seed: Math.random() * 1000, size: Math.random() < 0.08 ? 2.2 : 1.2 }));
    let t = 0;
    const frame = () => {
      this.raf = requestAnimationFrame(frame);
      if (document.hidden) return;
      t += 1;
      ctx.clearRect(0, 0, w, h);
      const lensX = w * 0.52, lensY = h * 0.5;
      // lens glow
      const g = ctx.createRadialGradient(lensX, lensY, 0, lensX, lensY, Math.min(w, h) * 0.32);
      g.addColorStop(0, 'rgba(160,140,255,0.16)'); g.addColorStop(1, 'rgba(160,140,255,0)');
      ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
      for (const p of ps) {
        const laneY = h * (0.24 + (p.lane / (lanes - 1)) * 0.52);
        let ty: number;
        if (p.x < lensX) {
          // chaos → converging toward the lens
          const k = Math.pow(p.x / lensX, 2.2);
          const noise = Math.sin((p.seed + t * 0.6) * 0.02) * h * 0.18 * (1 - k);
          ty = (1 - k) * (p.seed % h) + k * lensY + noise;
        } else {
          // ordered insight lanes
          const k = Math.min(1, (p.x - lensX) / (w * 0.18));
          ty = (1 - k) * lensY + k * laneY;
        }
        p.y += (ty - p.y) * 0.06;
        p.x += reduce ? 0 : p.v * (p.x > lensX ? 1.6 : 1);
        if (p.x > w + 10) { p.x = -10; p.seed = Math.random() * 1000; p.y = Math.random() * h; }
        const ordered = p.x > lensX;
        ctx.fillStyle = ordered ? colors[p.lane] : 'rgba(150,160,175,0.55)';
        ctx.globalAlpha = ordered ? 0.85 : 0.5;
        ctx.beginPath(); ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2); ctx.fill();
      }
      ctx.globalAlpha = 1;
      if (reduce) cancelAnimationFrame(this.raf);
    };
    frame();
  }
}
