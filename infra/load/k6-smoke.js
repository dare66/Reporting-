// k6 run -e BASE=http://localhost:8080 infra/load/k6-smoke.js
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  stages: [{ duration: '30s', target: 20 }, { duration: '1m', target: 50 }, { duration: '30s', target: 0 }],
  thresholds: { 'http_req_duration{kind:query}': ['p(95)<1000'], 'http_req_duration{kind:home}': ['p(95)<2000'], http_req_failed: ['rate<0.01'] },
};
const BASE = __ENV.BASE || 'http://localhost:8080';

export function setup() {
  const r = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email: 'analyst@emgs.demo', password: 'Demo@2026!' }), { headers: { 'Content-Type': 'application/json' } });
  return { token: r.json('access_token') };
}

export default function ({ token }) {
  const h = { headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' } };
  check(http.get(`${BASE}/api/v1/home`, { ...h, tags: { kind: 'home' } }), { home: r => r.status === 200 });
  check(http.post(`${BASE}/api/v1/query`, JSON.stringify({ model: 'decisions', metrics: ['sla_compliance'], dimensions: ['institution'], time: { range: 'last_30_days' } }), { ...h, tags: { kind: 'query' } }), { query: r => r.status === 200 });
  check(http.post(`${BASE}/api/v1/kpis`, JSON.stringify({ metrics: ['revenue.revenue', 'applications.total_applications'], range: 'last_30_days' }), { ...h, tags: { kind: 'query' } }), { kpis: r => r.status === 200 });
  sleep(1);
}
