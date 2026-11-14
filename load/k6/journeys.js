// WURK load tests (Sprint 18.3b) — the main journeys, mixed as real traffic would be.
//
//   k6 run load/k6/journeys.js                         # smoke: a few virtual users for 30 s
//   k6 run -e PROFILE=target load/k6/journeys.js      # the PRD target (staging only — Sprint 17)
//
// Environment: TARGET, APP_HOST, ROOT_DOMAIN, SITE_SCHEME, DATA, LOAD_TOKEN, PROFILE — see docs/performance/load-testing.md.
import http from 'k6/http';
import { sleep } from 'k6';
import { app, DATA, headers, ok, pick, site, TARGET, APP_HOST, THRESHOLDS, xsrf } from './lib.js';

const PROFILES = {
  // Proves the scripts and the environment work: tiny, quick.
  smoke: { public: 2, booking: 1, office: 1, signIn: 1, duration: '30s' },
  // PRD §15 at staging scale: 1,000 requests a second in total, for 15 minutes (after a 5-minute ramp).
  target: { public: 600, booking: 200, office: 180, signIn: 20, duration: '15m', ramp: '5m' },
};
const p = PROFILES[__ENV.PROFILE || 'smoke'];

function scenario(rate, exec, journey) {
  return p.ramp
    ? { executor: 'ramping-arrival-rate', exec, startRate: 1, timeUnit: '1s', preAllocatedVUs: Math.max(5, rate), maxVUs: rate * 4,
        stages: [{ target: rate, duration: p.ramp }, { target: rate, duration: p.duration }], tags: { journey } }
    : { executor: 'constant-arrival-rate', exec, rate, timeUnit: '1s', duration: p.duration, preAllocatedVUs: Math.max(2, rate * 2), maxVUs: rate * 10, tags: { journey } };
}

export const options = {
  scenarios: {
    public: scenario(p.public, 'publicSite', 'public'),
    booking: scenario(p.booking, 'booking', 'booking'),
    office: scenario(p.office, 'backOffice', 'back-office'),
    signIn: scenario(p.signIn, 'signIn', 'sign-in'),
  },
  // Smoke runs prove the journeys work (on any machine); timings are judged on staging, at the target profile.
  thresholds: (__ENV.PROFILE || 'smoke') === 'smoke' ? { http_req_failed: ['rate<0.01'], checks: ['rate>0.99'] } : THRESHOLDS,
  noCookiesReset: true, // an owner stays signed in between iterations, as in a browser
};

const day = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);

/** A visitor on a business's website: the booking widget's services and the published pages' assets. */
export function publicSite() {
  const b = pick(DATA.businesses);
  ok(site(b.handle, '/book/services', { headers: { Accept: 'application/json' } }), 'services');
}

/** A customer choosing a time to book. */
export function booking() {
  const b = pick(DATA.businesses);
  ok(site(b.handle, `/book/times?service=${b.service}&date=${day(1 + Math.floor(Math.random() * 13))}`, { headers: { Accept: 'application/json' } }), 'times');
}

/** An owner working in the back office: home, today's bookings, customers. Signed in once per virtual user. */
const signedIn = {};
export function backOffice() {
  const b = DATA.businesses[__VU % DATA.businesses.length];
  if (!signedIn[__VU]) {
    signIn(b);
    signedIn[__VU] = true;
  }
  for (const path of [`/${b.handle}`, `/${b.handle}/bookings`, `/${b.handle}/contacts`]) {
    ok(app(path, { redirects: 0 }), 'back office');
  }
}

/** Signing in with a password (as people do every morning). */
export function signIn(b = pick(DATA.businesses)) {
  app('/sign-in/password');
  const res = http.post(`${TARGET}/sign-in/password`, { identifier: b.owner, password: DATA.password },
    { headers: headers(APP_HOST, { 'X-XSRF-TOKEN': xsrf(`${TARGET}/`), Accept: 'text/html' }), redirects: 0, tags: { journey: 'sign-in' } });
  ok({ status: res.status === 302 && !String(res.headers.Location).includes('/sign-in') ? 200 : res.status, url: res.url, headers: res.headers }, 'sign in');
  sleep(0.1);
}
