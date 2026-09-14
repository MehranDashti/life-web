import http from 'k6/http';
import { fail } from 'k6';

export const BASE_URL = __ENV.BASE_URL || 'http://app:8080';
export const USERNAME = __ENV.LIFEWEB_USER || 'demo';
export const PASSWORD = __ENV.LIFEWEB_PASSWORD || 'password';

/**
 * Log in ONCE in setup() and share the token with every virtual user.
 *
 * Logging in per iteration would make bcrypt the dominant cost and turn the whole
 * load test into a measurement of BCRYPT_ROUNDS rather than of the API. Reusing a
 * token is also what a real client does.
 */
export function login() {
  const res = http.post(
    `${BASE_URL}/api/v1/auth/login`,
    JSON.stringify({ username: USERNAME, password: PASSWORD }),
    { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } },
  );

  if (res.status !== 200) {
    fail(`login failed with ${res.status}: ${res.body}`);
  }

  return res.json('data.access_token');
}

export function authHeaders(token) {
  return {
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
  };
}
