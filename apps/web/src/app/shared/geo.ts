import { feature } from 'topojson-client';
import type { GeometryCollection, Topology } from 'topojson-specification';

/** Natural Earth 110m geometry (bundled, offline). Business names → atlas names. */
export const GEO_ALIAS: Record<string, string> = {
  'United States': 'United States of America',
  'Czech Republic': 'Czechia',
  'Ivory Coast': "Côte d'Ivoire",
};
const SMALL: Record<string, [number, number]> = {
  Maldives: [3.2, 73.2],
  Singapore: [1.35, 103.8],
  Bahrain: [26.0, 50.55],
  Mauritius: [-20.3, 57.6],
};

export interface World {
  land: GeoJSON.FeatureCollection;
  countries: GeoJSON.FeatureCollection;
  centroid(name: string): [number, number] | null;
}

let world: Promise<World> | null = null;
export function loadWorld(): Promise<World> {
  world ??= Promise.all([
    fetch('geo/land-110m.json').then((r) => r.json() as Promise<Topology<{ land: GeometryCollection }>>),
    fetch('geo/countries-110m.json').then(
      (r) => r.json() as Promise<Topology<{ countries: GeometryCollection<{ name: string }> }>>,
    ),
  ]).then(([l, c]) => {
    const land = feature(l, l.objects.land);
    const countries = feature(c, c.objects.countries);
    const cache = new Map<string, [number, number] | null>();
    const centroid = (name: string): [number, number] | null => {
      const cached = cache.get(name);
      if (cached !== undefined) return cached;
      const atlasName = GEO_ALIAS[name] ?? name;
      const f = countries.features.find((x) => x.properties?.name === atlasName);
      let out: [number, number] | null = SMALL[name] ?? null;
      if (f) {
        // Centre of the largest ring's bounding box: robust for multi-part countries.
        const polys =
          f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : (f.geometry as GeoJSON.MultiPolygon).coordinates;
        const ring = polys.map((p) => p[0]).sort((a, b) => b.length - a.length)[0];
        const lons = ring.map((p) => p[0]),
          lats = ring.map((p) => p[1]);
        out = [(Math.min(...lats) + Math.max(...lats)) / 2, (Math.min(...lons) + Math.max(...lons)) / 2];
      }
      cache.set(name, out);
      return out;
    };
    return { land, countries, centroid };
  });
  return world;
}

/** Ray-casting point-in-polygon against land rings. */
export function onLand(land: GeoJSON.FeatureCollection, lon: number, lat: number): boolean {
  for (const f of land.features) {
    const polys =
      f.geometry.type === 'Polygon'
        ? [(f.geometry as GeoJSON.Polygon).coordinates]
        : (f.geometry as GeoJSON.MultiPolygon).coordinates;
    for (const poly of polys) {
      if (inRing(poly[0], lon, lat) && !poly.slice(1).some((h) => inRing(h, lon, lat))) return true;
    }
  }
  return false;
}

function inRing(ring: number[][], x: number, y: number): boolean {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [xi, yi] = ring[i],
      [xj, yj] = ring[j];
    if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inside = !inside;
  }
  return inside;
}
