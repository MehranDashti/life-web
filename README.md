# LifeWeb — scalable periodic reporting API

A Laravel 13 API that lets users subscribe to **periodic reports** over a corpus of
Persian news posts held in **Elasticsearch**. A report names a set of filter keywords
and a period (`daily` or `weekly`); on schedule the system produces an **Excel file
containing a daily post-count histogram** for those keywords and emails it to the
subscriber.

This repository implements the LifeWeb backend technical assessment (`task.pdf`).

---

## Status

| Area | State |
|---|---|
| Project foundation — layered architecture, response envelope, exception rendering, tooling, Docker | **Done** |
| Authentication — Passport personal access tokens, login / me / logout | **Done** |
| Elasticsearch integration — adapter contract, Persian analysis chain, monthly index template, `date_histogram` query path | **Done** |
| Index bootstrap and corpus import commands | Specified — `openspec/changes/elasticsearch-post-index` |
| Report subscriptions (create / list) | Specified — `openspec/changes/report-management` |
| Scheduled generation, queued jobs, run audit trail | Specified — `openspec/changes/scheduled-report-generation` |
| Excel export and delivery | Specified — `openspec/changes/excel-histogram-export` |
| Load test, benchmark, and the assessment's four written answers | Specified — `openspec/changes/load-test-and-readme` |

Work is spec-driven: nothing is implemented before its specification is written and
approved. `openspec list` shows the active changes; `openspec show <name>` reads one.

---

## Requirements

Docker and Docker Compose. Everything else runs in containers.

For running Artisan on the host instead: PHP 8.3 with `pdo_mysql`, `bcmath`, `zip`,
`gd`, `mbstring`, and Composer 2.

The two `mehrand/*` packages are private GitHub repositories; installing dependencies
needs SSH access to them.

## Quick start

```bash
cp .env.example .env
make build
make up                 # mysql + redis + elasticsearch + app (Octane/Swoole) + queue + scheduler
make fresh              # migrate + seed (creates the OAuth client and the demo user)

curl http://localhost:9900/api/v1/up
```

Demo credentials: `demo` / `password`.

The container ports are forwarded on deliberately non-standard host ports
(`33306` for MySQL, `63790` for Redis) so they don't collide with services already
running on the machine. Change them with `DB_FORWARD_PORT` / `REDIS_FORWARD_PORT`.

```bash
make help               # every target
make test               # unit + feature; no infrastructure required
make ci                 # style + static analysis + Rector + tests — run before every push
make hooks              # install the pre-commit hook (run once after cloning)
```

---

## API

Base path `/api/v1`. Every response uses the same envelope:

```jsonc
// success
{"success": true,  "code": 200, "message": "…", "data":  {…}}
// failure
{"success": false, "code": 422, "message": "…", "error": {…}}
```

Messages are Persian by default (`APP_LOCALE=fa`), with English as the fallback locale.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/up` | — | Health probe; reports database, cache and search independently |
| `POST` | `/auth/login` | — | Exchange username-or-email + password for a bearer token |
| `GET` | `/auth/me` | bearer | The authenticated user's profile |
| `POST` | `/auth/logout` | bearer | Revoke the token used for this request |

```bash
TOKEN=$(curl -s -X POST http://localhost:9900/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"demo","password":"password"}' | jq -r .data.access_token)

curl http://localhost:9900/api/v1/auth/me -H "Authorization: Bearer $TOKEN"
```

Sign-out revokes only the token that made the request, so signing out on one device
does not sign the user out everywhere.

The whole surface is rate-limited per authenticated user (per IP when anonymous) at
`API_RATE_LIMIT` requests per minute; `/auth/login` is additionally throttled.

---

## Architecture

```
Request
  → FormRequest                 validation only
    → Controller                thin: build a DTO, call one service method, shape the response
      → DTO::fromRequest()      typed payload; $request->all() never goes further
        → Service               business logic and orchestration
          → Mediator            business-rule guards, where a real invariant exists
          → Repository          the only code that touches Eloquent
            → Model
          → SearchAdapter       the only code that touches Elasticsearch
      → Resource                shapes the response body
```

The auth slice (`AuthController` → `LoginDTO` → `UserService` → `UserRepository` →
`User` → `UserResource`) is the reference implementation every later domain follows.
`CLAUDE.md` documents the layer order and the conventions in full.

### Elasticsearch

All search access goes through `App\Adapters\Contracts\SearchAdapterInterface`. No
service, job, or controller touches the Elasticsearch client directly, which is what
keeps the engine swappable, keeps the test suite infrastructure-free, and keeps every
query shape reviewable in one file.

Three decisions carry the design:

**The histogram is a `size: 0` aggregation, never a document fetch.** Counting posts
per day is a `date_histogram` over a filtered query with no hits returned. Paging
through documents to count them does not scale, and at a million posts it is orders of
magnitude slower. Zero-count days are preserved with `min_doc_count: 0` and
`extended_bounds` so the histogram has no holes.

**Indices are partitioned by month** — `posts-YYYY.MM`, created from an index template,
all behind the `posts` read alias. A report over a date range touches only the months it
covers, so query cost tracks the window the user asked for rather than the lifetime size
of the corpus. Retention becomes dropping an index rather than deleting by query.

**Persian text is normalised before analysis.** Arabic and Persian forms of the same
letter (ي/ی, ك/ک), Eastern Arabic digits, and zero-width non-joiners all appear in real
feeds. Without `arabic_normalization`, `persian_normalization` and `decimal_digit`, a
search for a word spelled with the Arabic yeh silently misses half the corpus. The
mapping is `dynamic: strict`, so a feed whose shape drifts fails loudly instead of
silently creating an unanalysed field.

Keyword and date-range clauses go in `bool.filter`, never `must` — filter clauses skip
scoring and are cacheable, and a histogram has no use for relevance.

### Scaling

The application tier is stateless and runs under Octane/Swoole, so it scales by adding
containers. Report generation is deliberately kept off the request path: the scheduler
only *dispatches*, enqueuing one job per due report, and throughput then scales with
`docker compose up --scale queue=N`. Runs are idempotent at the database level, so a
duplicate dispatch or a redelivered message cannot produce a duplicate report.

The full answers to the assessment's scalability questions, with measured numbers, are
produced by the `load-test-and-readme` change and will land in this README.

---

## Scope decisions

The task explicitly declares some things out of scope. Where it does, this project does
not build them — and says so rather than leaving the omission to be guessed at:

- **No role or permission system.** The task states that authorization checks are not
  important, so there is no `spatie/laravel-permission`, no roles, and no permission
  middleware. *Ownership* scoping is still enforced — a user only ever sees their own
  reports — because that is data integrity, not authorization.
- **Email delivery is not fully implemented.** `MAIL_MAILER=log` writes the rendered
  mail to the log, which keeps the flow observable without needing SMTP. The task states
  that delivery need not be complete and that the email's appearance does not matter.
- **Reports support create and list only.** Those are the routes the task names. A full
  CRUD surface was not added speculatively.
- **Send day and time are not configurable.** The task waives both.

---

## Testing

```bash
make test           # unit + feature — no infrastructure needed
make integration    # needs a live Elasticsearch
make ci             # the full gate
```

`SEARCH_DRIVER=fake` binds an in-memory search double in the test environment, so unit
and feature tests never require a running Elasticsearch. Only `tests/Integration` talks
to a real cluster, and it is excluded from CI.

Static analysis is Larastan/PHPStan at level 6 with no baseline and no suppressions.
Style is Pint; Rector runs in the gate as a report.

---

## Configuration

All configuration goes through `config()`; `env()` is never called from application
code, so `php artisan config:cache` is always safe. `.env.example` documents every key.
The ones worth knowing:

| Key | Meaning |
|---|---|
| `SEARCH_DRIVER` | `elasticsearch` or `fake` (tests) |
| `ELASTICSEARCH_HOSTS` | Comma-separated cluster hosts |
| `ELASTICSEARCH_INDEX_ALIAS` | Read alias the monthly indices sit behind |
| `ELASTICSEARCH_CHUNK_SIZE` | Documents per bulk request |
| `REPORT_TIMEZONE` | Timezone whose calendar days define histogram buckets |
| `API_RATE_LIMIT` | Requests per minute per authenticated user |
| `OCTANE_WORKERS` | Swoole worker count |

---

## Repository layout

```
app/
  Adapters/        Contracts/ (SearchAdapterInterface + value objects), Elasticsearch/, Fake/
  DTO/             Contracts/ + one folder per domain
  Enums/           backed enums — no const arrays on models
  Exceptions/      app-local renderers mapped in config/exceptions.php
  Http/            Controllers/Api/V1, Requests, Resources, Filters
  Mediators/       business-rule guards
  Repositories/    Contracts/ (interfaces) + implementations
  Services/        business logic
docker/            Dockerfile, entrypoint, supervisord, php.ini
loadtest/          k6 scenarios and committed results
openspec/          living specs and active change proposals
CLAUDE.md          architecture, conventions, and the mandatory spec-driven workflow
```
