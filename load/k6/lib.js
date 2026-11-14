// WURK load tests (Sprint 18.3b) — shared helpers. See docs/performance/load-testing.md.
import http from 'k6/http';
import { check } from 'k6';

export const TARGET = __ENV.TARGET || 'http://127.0.0.1:8123'; // where requests go (staging: https://app.<root>)
export const APP_HOST = __ENV.APP_HOST || ''; // Host header for the back office when TARGET is an IP
export const ROOT = __ENV.ROOT_DOMAIN || 'wurk.test'; // businesses' sites: <handle>.<root>
export const DATA = JSON.parse(open(__ENV.DATA || '../../storage/app/load-test.json')); // from `php artisan wurk:seed-load`

// One made-up visitor address per virtual user, so per-visitor rate limits treat them as different people
// (only honoured on staging, with WURK_LOAD_TEST_TOKEN — see LoadTestVisitors).
const visitor = () => `10.${(__VU >> 16) & 255}.${(__VU >> 8) & 255}.${__VU & 255}`;

export function headers(host, extra = {}) {
  const h = { 'User-Agent': 'WURK load test (k6)', ...extra };
  if (host) h.Host = host;
  if (__ENV.LOAD_TOKEN) {
    h['X-Wurk-Load-Test'] = __ENV.LOAD_TOKEN;
    h['X-Wurk-Load-Visitor'] = visitor();
  }
  return h;
}

/** A business's site: same server, its own host name. */
export function site(handle, path, params = {}) {
  const host = `${handle}.${ROOT}`;
  const url = __ENV.SITE_SCHEME ? `${__ENV.SITE_SCHEME}://${host}${path}` : `${TARGET}${path}`;
  return http.get(url, { headers: headers(__ENV.SITE_SCHEME ? '' : host, params.headers), tags: params.tags });
}

export function app(path, params = {}) {
  return http.get(`${TARGET}${path}`, { headers: headers(APP_HOST, params.headers), tags: params.tags, redirects: params.redirects ?? 5 });
}

/** The XSRF token Laravel set in a cookie, for a form post. */
export function xsrf(url) {
  const jar = http.cookieJar();
  const c = jar.cookiesForURL(url)['XSRF-TOKEN'];
  return c && c.length ? decodeURIComponent(c[0]) : '';
}

export function pick(list) {
  return list[Math.floor(Math.random() * list.length)];
}

export function ok(res, name) {
  const good = check(res, { [`${name}: 2xx`]: (r) => r.status >= 200 && r.status < 300 });
  if (!good && __ENV.DEBUG) console.warn(`${name}: ${res.status} ${res.url || ''} → ${(res.headers && res.headers.Location) || ''}`);
  return good;
}

// PRD §15: 95% of requests within 300 ms; fewer than 0.1% failing.
export const THRESHOLDS = {
  http_req_failed: ['rate<0.001'],
  'http_req_duration{journey:public}': ['p(95)<300'],
  'http_req_duration{journey:booking}': ['p(95)<300'],
  'http_req_duration{journey:back-office}': ['p(95)<300'],
  'http_req_duration{journey:sign-in}': ['p(95)<600'], // password hashing is deliberately slow
  checks: ['rate>0.999'],
};
