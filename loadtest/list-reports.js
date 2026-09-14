import http from 'k6/http';
import { check } from 'k6';
import { Trend } from 'k6/metrics';
import { BASE_URL, login, authHeaders } from './lib/auth.js';

/**
 * Read-only scenario at a fixed arrival rate.
 *
 * A constant-arrival-rate executor rather than fixed VUs: it keeps offering the
 * same load as latency rises, which is what actually exposes a saturation point.
 * Fixed VUs would quietly reduce throughput instead.
 */
const listTrend = new Trend('lifeweb_list_reports', true);

export const options = {
  scenarios: {
    steady: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.RATE || 200),
      timeUnit: '1s',
      duration: __ENV.DURATION || '60s',
      preAllocatedVUs: 50,
      maxVUs: 400,
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    // Reported so generator saturation is visible rather than mistaken for
    // server-side latency.
    dropped_iterations: ['count<100'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(95)', 'p(99)', 'max', 'count'],
};

export function setup() {
  return { token: login() };
}

export default function (data) {
  const res = http.get(`${BASE_URL}/api/v1/reports?page_size=15`, authHeaders(data.token));
  listTrend.add(res.timings.duration);

  check(res, {
    '200': (r) => r.status === 200,
    'envelope': (r) => r.json('success') === true,
  });
}
