import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  NgZone,
  OnDestroy,
  afterNextRender,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { Theme } from '../core/theme.service';
import { fmt } from '../core/format';
import { loadWorld, onLand } from './geo';

export interface GlobeDatum {
  name: string;
  value: number;
}

/**
 * Analytical 3D globe: a dotted-land sphere with value columns rising from each
 * country (height ∝ value). Rotate, zoom, tap a country to drill. Every globe
 * has a 2D alternative in its host (ranked bars / choropleth).
 */
/** A value column on the globe; userData carries what the tooltip shows. */
type Bar = THREE.Mesh<THREE.CylinderGeometry, THREE.MeshBasicMaterial> & {
  userData: { name: string; value: number };
};

@Component({
  selector: 'app-globe',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: ` <div #host class="host" [style.height.px]="height()"></div>
    @if (hover(); as h) {
      <div class="tip" [style.left.px]="h.x" [style.top.px]="h.y">
        <b>{{ h.name }}</b
        ><span>{{ h.value }}</span>
      </div>
    }
    <div class="legend"><span class="bar"></span>{{ legend() }}</div>`,
  styles: [
    `
      :host {
        display: block;
        position: relative;
      }
      .host {
        width: 100%;
        cursor: grab;
      }
      .host:active {
        cursor: grabbing;
      }
      .tip {
        position: absolute;
        pointer-events: none;
        transform: translate(-50%, -120%);
        background: var(--bg-2);
        border: 1px solid var(--line-2);
        border-radius: 10px;
        padding: 6px 10px;
        display: grid;
        font-size: 12px;
        white-space: nowrap;
      }
      .legend {
        position: absolute;
        left: 12px;
        bottom: 10px;
        font-size: 11.5px;
        color: var(--ink-3);
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .legend .bar {
        width: 3px;
        height: 14px;
        border-radius: 2px;
        background: var(--accent);
      }
    `,
  ],
})
export class Globe implements OnDestroy {
  readonly data = input<GlobeDatum[]>([]);
  readonly format = input('number');
  readonly height = input(380);
  readonly legend = input('Column height = value');
  readonly autoRotate = input(true);
  readonly pick = output<string>();
  readonly hover = signal<{ x: number; y: number; name: string; value: string } | null>(null);

  private host = viewChild.required<ElementRef<HTMLDivElement>>('host');
  private theme = inject(Theme);
  private zone = inject(NgZone);
  private renderer?: THREE.WebGLRenderer;
  private scene = new THREE.Scene();
  private camera = new THREE.PerspectiveCamera(40, 1, 0.1, 100);
  private controls?: OrbitControls;
  private group = new THREE.Group();
  private sphere = new THREE.Mesh(
    new THREE.SphereGeometry(0.995, 64, 64),
    new THREE.MeshBasicMaterial({ color: 0x0c1016, transparent: true, opacity: 0.92 }),
  );
  /** Value columns; kept in a typed list alongside the scene group that renders them. */
  private bars = new THREE.Group();
  private barMeshes: Bar[] = [];
  private dots?: THREE.Points<THREE.BufferGeometry, THREE.PointsMaterial>;
  private frame = 0;
  private ro?: ResizeObserver;
  private raycaster = new THREE.Raycaster();
  private pointer = new THREE.Vector2(-9, -9);

  constructor() {
    afterNextRender(() => this.zone.runOutsideAngular(() => this.init()));
    effect(() => {
      this.data();
      this.theme.version();
      this.renderBars();
      this.recolor();
    });
  }

  ngOnDestroy() {
    cancelAnimationFrame(this.frame);
    this.ro?.disconnect();
    this.controls?.dispose();
    this.renderer?.dispose();
    this.scene.traverse((o) => {
      if (o instanceof THREE.Mesh || o instanceof THREE.Points) {
        o.geometry.dispose();
        for (const m of [o.material].flat()) m.dispose();
      }
    });
  }

  private async init() {
    const el = this.host().nativeElement;
    let renderer: THREE.WebGLRenderer;
    try {
      renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    } catch {
      el.innerHTML =
        '<p style="padding:24px;color:var(--ink-3)">3D is not available on this device — use the 2D view.</p>';
      return;
    }
    this.renderer = renderer;
    renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
    el.appendChild(renderer.domElement);
    this.camera.position.set(0, 0.6, 3.1);
    const controls = (this.controls = new OrbitControls(this.camera, renderer.domElement));
    controls.enableDamping = true;
    controls.enablePan = false;
    controls.minDistance = 1.8;
    controls.maxDistance = 5;
    controls.autoRotate = this.autoRotate() && !matchMedia('(prefers-reduced-motion: reduce)').matches;
    controls.autoRotateSpeed = 0.45;

    this.group.add(this.sphere);
    const ring = new THREE.Mesh(
      new THREE.RingGeometry(1.0, 1.18, 96),
      new THREE.MeshBasicMaterial({ color: 0x3987e5, transparent: true, opacity: 0.05, side: THREE.DoubleSide }),
    );
    ring.name = 'halo';
    this.scene.add(ring);
    this.group.add(this.bars);
    this.group.rotation.y = -((95 + 90) * Math.PI) / 180; // open facing ~95°E (South & East Asia), where the demand comes from
    this.scene.add(this.group);

    const world = await loadWorld();
    const positions: number[] = [];
    for (let lat = -80; lat <= 82; lat += 1.7) {
      const step = 1.7 / Math.max(0.2, Math.cos((lat * Math.PI) / 180));
      for (let lon = -180; lon < 180; lon += step) {
        if (onLand(world.land, lon, lat)) {
          const v = toVec(lat, lon, 1.002);
          positions.push(v.x, v.y, v.z);
        }
      }
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    this.dots = new THREE.Points(
      geo,
      new THREE.PointsMaterial({ size: 0.014, color: 0x7c8594, transparent: true, opacity: 0.8 }),
    );
    this.group.add(this.dots);
    this.recolor();
    await this.renderBars();

    el.addEventListener('pointermove', (e) => {
      const r = el.getBoundingClientRect();
      this.pointer.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
    });
    el.addEventListener('pointerleave', () => {
      this.pointer.set(-9, -9);
      this.zone.run(() => this.hover.set(null));
    });
    el.addEventListener('click', () => {
      const hit = this.hit();
      if (hit) this.zone.run(() => this.pick.emit(hit.userData.name));
    });

    const resize = () => {
      const w = el.clientWidth;
      const h = this.height();
      renderer.setSize(w, h);
      this.camera.aspect = w / h;
      this.camera.updateProjectionMatrix();
    };
    this.ro = new ResizeObserver(resize);
    this.ro.observe(el);
    resize();

    const loop = () => {
      this.frame = requestAnimationFrame(loop);
      controls.update();
      ring.lookAt(this.camera.position);
      const hit = this.hit();
      for (const b of this.barMeshes) b.material.opacity = hit ? (b === hit ? 1 : 0.35) : 0.9;
      if (hit) {
        const p = hit.getWorldPosition(new THREE.Vector3()).project(this.camera);
        const x = ((p.x + 1) / 2) * el.clientWidth;
        const y = ((1 - p.y) / 2) * this.height();
        const name = hit.userData.name;
        const value = fmt(hit.userData.value, this.format());
        const cur = this.hover();
        if (!cur || cur.name !== name || Math.abs(cur.x - x) > 2)
          this.zone.run(() => this.hover.set({ x, y, name, value }));
      } else if (this.hover()) this.zone.run(() => this.hover.set(null));
      renderer.render(this.scene, this.camera);
    };
    loop();
  }

  /** The bar under the pointer, unless the globe itself is in front of it. */
  private hit(): Bar | null {
    if (this.pointer.x < -1) return null;
    this.raycaster.setFromCamera(this.pointer, this.camera);
    const front = this.raycaster.intersectObjects<THREE.Object3D>([this.sphere, ...this.barMeshes], false)[0]?.object;
    return this.barMeshes.find((b) => b === front) ?? null;
  }

  private recolor() {
    const accent = new THREE.Color(this.theme.token('--accent') || '#e8b04b');
    const dark = this.theme.resolved() === 'dark';
    this.sphere.material.color.set(dark ? 0x0c1016 : 0xe9e7e1);
    this.dots?.material.color.set(dark ? 0x7c8594 : 0x9a978f);
    for (const b of this.barMeshes) b.material.color.copy(accent);
  }

  private async renderBars() {
    if (!this.renderer) return;
    const world = await loadWorld();
    for (const b of this.barMeshes) {
      b.geometry.dispose();
      b.material.dispose();
    }
    this.bars.clear();
    this.barMeshes = [];
    const max = Math.max(...this.data().map((d) => d.value), 1);
    const accent = new THREE.Color(this.theme.token('--accent') || '#e8b04b');
    for (const d of this.data()) {
      const c = world.centroid(d.name);
      if (!c) continue;
      const h = 0.02 + 0.55 * (d.value / max);
      const geo = new THREE.CylinderGeometry(0.012, 0.012, h, 10);
      geo.translate(0, h / 2, 0);
      const material = new THREE.MeshBasicMaterial({ color: accent, transparent: true, opacity: 0.9 });
      const bar: Bar = Object.assign(new THREE.Mesh(geo, material), { userData: { name: d.name, value: d.value } });
      const pos = toVec(c[0], c[1], 1.0);
      bar.position.copy(pos);
      bar.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), pos.clone().normalize());
      this.bars.add(bar);
      this.barMeshes.push(bar);
    }
  }
}

function toVec(lat: number, lon: number, r: number): THREE.Vector3 {
  const phi = ((90 - lat) * Math.PI) / 180,
    theta = ((lon + 180) * Math.PI) / 180;
  return new THREE.Vector3(
    -r * Math.sin(phi) * Math.cos(theta),
    r * Math.cos(phi),
    r * Math.sin(phi) * Math.sin(theta),
  );
}
