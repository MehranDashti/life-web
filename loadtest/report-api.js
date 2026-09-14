import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';
import { BASE_URL, login, authHeaders } from './lib/auth.js';

/**
 * API behaviour under rising concurrency.
 *
 * Staged ramp with a plateau at each level, and the ramps themselves excluded from
 * the reported statistics: percentiles computed across a ramp describe the ramp,
 * not the system. Each plateau is tagged so it can be reported as its own row and
 * the knee in the curve is visible.
 */
const listTrend = new Trend('lifeweb_list_reports', true);
const createTrend = new Trend('lifeweb_create_report', true);
const businessErrors = new Counter('lifeweb_business_errors');

export const options = {
  discardResponseBodies: false,
  scenarios: {
    ramp: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '10s', target: 10 },  { duration: '30s', target: 10 },
        { duration: '10s', target: 25 },  { duration: '30s', target: 25 },
        { duration: '10s', target: 50 },  { duration: '30s', target: 50 },
        { duration: '10s', target: 100 }, { duration: '30s', target: 100 },
        { duration: '10s', target: 200 }, { duration: '30s', target: 200 },
        { duration: '10s', target: 0 },
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    // A broken run must fail loudly rather than reporting flattering latency for
    // requests that were actually failing.
    http_req_failed: ['rate<0.01'],
    lifeweb_business_errors: ['count<1'],
    http_req_duration: ['p(95)<2000', 'p(99)<5000'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(95)', 'p(99)', 'max', 'count'],
};

export function setup() {
  return { token: login() };
}

export default function (data) {
  const auth = authHeaders(data.token);

  // Read-dominated, as a reporting API is in practice.
  const list = http.get(`${BASE_URL}/api/v1/reports?page_size=15`, auth);
  listTrend.add(list.timings.duration);

  const listOk = check(list, {
    'list 200': (r) => r.status === 200,
    'list envelope': (r) => r.json('success') === true,
  });
  if (!listOk) businessErrors.add(1);

  // One write in every ten iterations, so the write path is exercised without the
  // table growing so fast that the list query becomes the thing being measured.
  if (__ITER % 10 === 0) {
    const create = http.post(
      `${BASE_URL}/api/v1/reports`,
      JSON.stringify({
        name: `بار ${__VU}-${__ITER}`,
        period: 'daily',
        keywords: ['تهران'],
      }),
      auth,
    );
    createTrend.add(create.timings.duration);

    const createOk = check(create, {
      'create 200': (r) => r.status === 200,
      'create envelope': (r) => r.json('success') === true,
    });
    if (!createOk) businessErrors.add(1);
  }

  sleep(0.1);
}
