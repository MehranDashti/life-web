# LifeWeb — scalable periodic reporting API

A Laravel 13 API where users subscribe to **periodic reports** over a corpus of
Persian news posts held in **Elasticsearch**. A report names filter keywords and a
period (`daily` or `weekly`); on schedule the system builds an **Excel file
containing a daily post-count histogram** for those keywords and emails it to the
subscriber.

This implements the LifeWeb backend technical assessment (`task.pdf`).

---

## Contents

- [Quick start](#quick-start)
- [Using the API](#using-the-api)
- [Postman](#postman)
- [How it works](#how-it-works)
- [Assessment answers](#assessment-answers)
  - [1. Scaling with requests and users](#1-scaling-with-requests-and-users)
  - [2. Very large data ranges](#2-very-large-data-ranges)
  - [3. User-selectable delivery channels](#3-user-selectable-delivery-channels)
  - [4. Benchmark results](#4-benchmark-results)
- [Scope decisions](#scope-decisions)
- [Production readiness](#production-readiness)
- [Development](#development)

---

## Quick start

You need Docker and Docker Compose. Everything else runs in containers.

```bash
cp .env.example .env
make build          # builds the app image (bakes the source — re-run after code changes)
make up             # mysql + redis + elasticsearch + app (Octane/Swoole) + queue + scheduler
make fresh          # migrate, seed the OAuth client + demo user, and fill Elasticsearch from data.json

curl http://localhost:9900/api/v1/up
```

`make fresh` also runs `PostSeeder`, which creates the Elasticsearch index template
and imports the 21 posts in `data.json`. If Elasticsearch is not up yet the seeder
logs a warning and skips rather than failing — run `make index` once it is.

Demo credentials: **`demo` / `password`**

Container ports are forwarded on deliberately non-standard host ports so they don't
collide with a MySQL or Redis already running on your machine:

| Service | Host port | Override |
|---|---|---|
| API | `9900` | `APP_PORT` |
| MySQL | `33306` | `DB_FORWARD_PORT` |
| Redis | `63790` | `REDIS_FORWARD_PORT` |
| Elasticsearch | `9200` | `ES_FORWARD_PORT` |

---

## Using the API

Base path `/api/v1`. Every JSON response uses the same envelope:

```jsonc
// success
{"success": true,  "code": 200, "message": "…", "data":  { … }}
// failure
{"success": false, "code": 422, "message": "…", "error": { … }}
```

Messages are Persian by default (`APP_LOCALE=fa`), with English as the fallback.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/up` | — | Health probe; reports database, cache and search separately |
| `POST` | `/auth/login` | — | Exchange username-or-email + password for a bearer token |
| `GET` | `/auth/me` | bearer | The authenticated user's profile |
| `POST` | `/auth/logout` | bearer | Revoke the token used for this request |
| `POST` | `/reports` | bearer | **Create a report subscription** |
| `GET` | `/reports` | bearer | **List the caller's reports** |
| `GET` | `/reports/{report}` | bearer | Read one report |
| `POST` | `/reports/{report}/run` | bearer | Generate now, without waiting for the scheduler |
| `GET` | `/reports/{report}/runs` | bearer | Execution history |
| `GET` | `/reports/{report}/runs/{run}/download` | bearer | Download the generated `.xlsx` |

The assessment names three routes — login, create report, list reports. The last
four exist because the deliverable is otherwise only observable by waiting for the
scheduler and reading the mail log; see [Scope decisions](#scope-decisions).

### Walkthrough

```bash
BASE=http://localhost:9900/api/v1

# 1. Sign in. Accepts a username OR an email.
TOKEN=$(curl -s -X POST $BASE/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"demo","password":"password"}' | jq -r .data.access_token)

# 2. Create a report subscription.
REPORT=$(curl -s -X POST $BASE/reports \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"آلودگی هوای تهران","period":"daily","keywords":["تهران","آلودگی"]}' \
  | jq -r .data.id)

# 3. List your reports.
curl -s $BASE/reports -H "Authorization: Bearer $TOKEN" | jq .data

# 4. Generate immediately. The supplied corpus spans 2024-12-18 → 2024-12-21,
#    so that window is the one with interesting numbers.
RUN=$(curl -s -X POST $BASE/reports/$REPORT/run \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"from":"2024-12-18","to":"2024-12-21"}' | jq -r .data.id)

# 5. Download the workbook.
curl -s -o report.xlsx $BASE/reports/$REPORT/runs/$RUN/download -H "Authorization: Bearer $TOKEN"
```

The scheduled path needs no waiting either:

```bash
docker compose exec app php artisan reports:dispatch daily --sync
docker compose logs queue | tail        # the job ran
docker compose logs app | grep -i subject   # MAIL_MAILER=log records the send
```

### Creating a report

```jsonc
{
  "name": "آلودگی هوای تهران",   // required, 2–255 chars
  "period": "daily",              // required: daily | weekly
  "keywords": ["تهران", "آلودگی"], // required, 1–10 items, each 2–64 chars
  "news_agency_ids": ["mehr"],    // optional, narrows to specific agencies
  "match_all_keywords": false     // optional; false = match ANY, true = require ALL
}
```

The keyword cap is deliberate: every keyword becomes a `multi_match` clause, so an
unbounded list would make every scheduled run expensive. A `user_id` in the body is
ignored — a report always belongs to the caller.

### The generated workbook

One sheet, right-to-left, with the header frozen:

| | |
|---|---|
| Rows 1–7 | Metadata: report name, keywords, period, window, total matched, generated-at |
| Row 8 | Column headers |
| Rows 9+ | One row per day: Gregorian date, Jalali date, post count |

**Every day in the window appears, including days with zero posts.** Skipping empty
days would silently compress the time axis and misrepresent the shape of the data.

### Errors

| Code | When |
|---|---|
| 401 | Missing, invalid or revoked bearer token |
| 403 | The report belongs to another user |
| 404 | Unknown report or run, or a run that produced no file |
| 405 | Wrong HTTP method |
| 422 | Validation failure; `error` carries per-field messages |
| 503 | Elasticsearch unreachable — reporting degrades, the API stays up |

The whole surface is rate limited per authenticated user (per IP when anonymous) at
`API_RATE_LIMIT` requests/minute; `/auth/login` is additionally throttled to 10/min
and `/reports/{id}/run` to 20/min.

---

## Postman

`postman/` contains a v2.1 collection and an environment.

1. Import both files into Postman.
2. Select the **LifeWeb Local** environment.
3. Run **Auth → Login**. A test script captures the token into a collection
   variable, so every other request authorises with no copy-paste. Creating a
   report and running it likewise store `report_id` and `run_id`.
4. Work down the folders: Health → Auth → Reports → Report runs.

For the download request use Postman's **Send and Download**.

---

## How it works

```
Request
  → FormRequest                 validation only
    → Controller                thin: build a DTO, call one service method, shape the response
      → DTO::fromRequest()      typed payload; $request->all() never goes further
        → Service               business logic and orchestration
          → Mediator            business-rule guards (ownership)
          → Repository          the only code that touches Eloquent
          → SearchAdapter       the only code that touches Elasticsearch
      → Resource                shapes the response body
```

Scheduled generation runs on the queue, never in the request:

```
scheduler container          queue container(s)
reports:dispatch daily  ──▶  GenerateReportJob (one per report)
  one indexed range scan       ├─ claim the run row     (unique index = idempotency)
  enqueue, compute nothing     ├─ date_histogram        (size: 0 aggregation)
                               ├─ stream XLSX           (openspout, constant memory)
                               └─ deliver               (separate step; failure ≠ regenerate)
```

### Elasticsearch

All search access goes through `App\Adapters\Contracts\SearchAdapterInterface`. No
service, job or controller touches the client directly, which keeps the engine
swappable, the test suite infrastructure-free, and every query shape reviewable in
one file.

Three decisions carry the design:

**The histogram is a `size: 0` aggregation, never a document fetch.** Counting posts
per day is a `date_histogram` over a filtered query that returns no hits. Paging
through documents to count them does not scale — and the benchmark below shows why
this matters: matching 298,663 documents costs the same 2 ms as matching 6.

**Indices are partitioned by month** — `posts-YYYY.MM`, created from an index
template, all behind the `posts` read alias. A report over a date range touches only
the months it covers, so query cost tracks the window rather than the lifetime size
of the corpus. Retention becomes dropping an index rather than deleting by query.

**Persian text is normalised before analysis.** Arabic and Persian forms of the same
letter (ي/ی, ك/ک), Eastern Arabic digits and zero-width non-joiners all appear in
real feeds. Without `arabic_normalization`, `persian_normalization` and
`decimal_digit`, a search for a word spelled with the Arabic yeh silently misses
half the corpus. The mapping is `dynamic: strict`, so a feed whose shape drifts
fails loudly instead of creating an unanalysed field.

Keyword and date-range clauses go in `bool.filter`, never `must` — filter clauses
skip scoring and are cacheable, and a histogram has no use for relevance.

### Caching

**This reduces load on Elasticsearch, not latency.** The benchmark below measures the
aggregation at 1–2 ms flat from 10k to 1M documents, so there is no single-request
speed-up to be had. What there is: search is only reached during report generation,
so load concentrates entirely on the scheduler tick — and many due reports issue
*identical* aggregations, because different users track overlapping keywords over the
same window.

`CachedSearchAdapter` is a decorator over `SearchAdapterInterface`, keyed by
`HistogramQuery::signature()`. Measured against Elasticsearch's own `query_total`
counter:

| | Identical queries issued | Aggregations reaching the engine |
|---|---:|---:|
| Cache on | 50 | **1** |
| Cache off | 50 | 50 |

Three decisions are load-bearing:

- **Version-stamped keys, not tag flushing.** Every write to the index increments one
  integer that forms part of the key, so a re-import invalidates everything at once in
  constant time. Tag flushing would mean tracking and deleting every key on every import.
- **Primitives, never object graphs.** The payload is plain arrays, rehydrated on read.
  This project already shipped one bug from caching a rich object — a `JsonResource`
  collection came back as `__PHP_Incomplete_Class` and the list endpoint served garbage.
- **A cache never fails a request.** Any store failure falls through to Elasticsearch.
  A Redis outage slows the system down; it does not break it.

A cache hit reports `query_took_ms` of 0, because no engine time was spent — which makes
the collapse visible in `report_runs` rather than only in a test.

Redis also backs the rate limiter and the queue's unique-job lock. Both must be shared
across application instances: with a per-process store, N containers keep N rate-limit
buckets and a caller's real limit becomes N×the configured one.

### Idempotency

`report_runs` carries `unique(report_id, period_start, period_end)`. The job claims
its run row **before** doing any work, so a duplicate dispatch, an overlapping
scheduler tick or a redelivered queue message cannot produce a second report or a
second email. The claim distinguishes outcomes: a *succeeded* window is refused, a
*failed* window is reclaimed and retried, and a *running* window is refused while
fresh but reclaimed after 15 minutes so a worker that died mid-run cannot block it
forever.

---

## Assessment answers

All figures below were measured on this project, not estimated. Method and hardware
are in [Benchmark results](#4-benchmark-results); raw output is committed under
`loadtest/results/`.

### 1. Scaling with requests and users

The design keeps three things separate, and each scales differently.

**The API tier is stateless and scales horizontally.** It runs under Octane/Swoole
with persistent workers, so there is no per-request bootstrap. Sessions are not used;
authentication is a bearer token validated against the database. Adding API
containers behind a load balancer is the whole scaling story for request volume.
Measured: **487 req/s sustained with 0% errors, p95 185 ms, p99 232 ms** on a single
container with 8 workers.

**Report generation never happens in a request.** The scheduler runs one indexed
range scan — `status = 'active' AND next_run_at <= now()`, covered by a composite
index on `(status, next_run_at)` — and enqueues one job per due report. It computes
nothing. Its cost is proportional to the number of due reports and completely
independent of corpus size. A scheduler that generated reports inline would
serialise every user behind one process, which is the exact bottleneck this question
is about.

**Throughput of the reports themselves scales with worker count.** One job per
report, on a dedicated `reports` queue, with no coordination between workers beyond
the idempotency constraint. `docker compose up --scale queue=8` multiplies report
throughput without touching the API containers. Because the jobs are independent,
one report failing cannot affect any other.

Where the next bottleneck appears, in order:

1. **MySQL connections**, long before CPU. Each Octane worker and each queue worker
   holds a connection; a few hundred containers exhausts `max_connections`. The fix
   is a connection pooler (ProxySQL/PgBouncer-equivalent), not more app containers.
2. **Elasticsearch shard count**, once the corpus outgrows one node. Monthly indices
   already partition the data, so this is adding data nodes and raising
   `number_of_shards` for new months — no application change.
3. **A thundering herd on the scheduler tick.** All daily reports become due at the
   same instant. Mitigated twice over: the enqueue is jittered across a window, and
   identical aggregations are collapsed by the search cache — measured at 50 identical
   queries costing 1 aggregation. At much larger scale the dispatch itself would be
   sharded by report id.

What is *not* a bottleneck, per the measurements below: the aggregation query. It
costs 1–2 ms whether the corpus holds 10,000 or 1,000,000 documents.

### 2. Very large data ranges

This is where the Elasticsearch integration earns its keep. Four decisions, in
order of how much they matter:

**Never fetch documents to count them.** The histogram is a `size: 0` query with a
`date_histogram` aggregation. Elasticsearch counts inside the shard and returns
buckets — the documents themselves never cross the wire and are never deserialised
in PHP. The benchmark makes the consequence concrete: a 365-day window matching
**298,663 documents** returns in **2 ms**, the same as a 1-day window matching 837.
Cost tracks the number of *buckets*, not the number of *matches*.

**Partition indices by month.** `posts-YYYY.MM` behind the `posts` read alias. A
query for March only opens March's index; the other months are never touched.
Retention is `DELETE /posts-2023.01` rather than a delete-by-query that rewrites
segments. Old months can be moved to slower storage or frozen with no application
change.

**Use filter context, not query context.** Keyword and range clauses sit in
`bool.filter`, which skips scoring entirely and is cacheable. A histogram has no use
for relevance ranking, so paying for it would be pure waste.

**Stream the export.** `openspout` writes row by row; nothing accumulates in memory.
Measured peak memory is **~52 MB regardless of corpus size or window length** — a
365-day window costs the same as a 1-day one. PhpSpreadsheet was rejected precisely
because it builds the whole workbook in RAM, which would have put a ceiling on the
export that the query path does not have.

If the range grew beyond what a single aggregation should do, the next steps — not
implemented, and not needed at the scale measured — would be:

- **`composite` aggregation with `after_key` paging** when bucket counts get large
  (years of hourly buckets), so results stream instead of arriving as one response.
- **Async search** (`_async_search`) for windows expected to exceed the request
  timeout, polling for completion rather than holding a connection.
- **Rollup / downsampled indices**: pre-aggregate daily counts per keyword set once,
  then serve long ranges from the rollup. This turns a multi-year report into a
  read of a few hundred pre-computed rows.
- **Frozen or searchable-snapshot tiers** for months older than the retention window,
  trading query latency for storage cost.

### 3. User-selectable delivery channels

**Not implemented — this is a design proposal.** The task asks what approach I would
take, and the current code is deliberately built so this is an additive change
rather than a refactor.

The seam already exists. `GenerateReportJob` treats delivery as a step *distinct
from* generation: the run is marked succeeded once the file is on disk, and a
delivery failure is recorded on the run (`delivery_error`) without discarding the
artifact or causing the histogram to be recomputed on retry. Generation and delivery
are already decoupled; only the fan-out is missing.

The shape I would add:

```
report_channels                          DeliveryChannel (interface)
  report_id                                deliver(ReportRun, array $config): void
  type       email | webhook | telegram    supports(ReportRun): bool
  config     json (address, url, chat id)
  is_active
```

- A `DeliveryChannelRegistry` resolves `type` → implementation through one `match`,
  exactly as `AppServiceProvider` already resolves the search driver. Adding a
  channel means adding a class and a match arm, never a conditional inside a service.
- After a successful run, dispatch **one `DeliverReportJob` per active channel**,
  not one job that loops. This is the important part: a Telegram outage must not
  block or retry the email, and each channel gets its own retry budget and backoff.
- A `report_deliveries` table — `(run_id, channel_id, status, attempts, error,
  delivered_at)` with `unique(run_id, channel_id)` — gives per-channel idempotency
  on exactly the same principle the run table already uses, so a redelivered queue
  message cannot double-send to one channel.
- Validation caps the number of active channels per report, for the same reason
  keywords are capped: an unbounded fan-out is an unbounded cost per run.
- The existing `ReportReadyMail` becomes the `email` implementation unchanged.

Migration is additive: seed one `email` channel per existing report from
`users.email` and the current behaviour is preserved exactly.

### 4. Benchmark results

**Hardware.** Intel Core i7-12700K (20 threads), 31 GB RAM, Ubuntu (kernel 7.0.0-31),
Docker 28.1.1. Everything — load generator included — ran on this one machine.

**Stack.** PHP 8.3.31, Laravel 13.31, Octane/Swoole with 8 workers, MySQL 8.4.11,
Elasticsearch 8.15.3 single node with a 2 GB heap, Redis 7. Measured 2026-09-14.

**Method.**

- Synthetic corpora are generated deterministically (`posts:synthetic --seed`), spread
  uniformly across a fixed 2024 publication range so a fixed-width report window
  always selects the same *fraction* of the corpus at every size.
- Each index is force-merged to one segment before measuring, so query time reflects
  corpus size rather than segment count.
- Every size runs a **discarded warm-up pass** before the measured passes.
- Elasticsearch's own reported `took` and PHP wall-clock are recorded separately; the
  gap between them is transport plus deserialisation.
- The API rate limiter is raised for the capacity run. Every virtual user shares one
  token and therefore one bucket, so leaving it at the default 120/min would measure
  the limiter rather than the API.

Reproduce with `make bench` (corpus sweep) and `make loadtest` (API). Raw output is
in `loadtest/results/`.

#### Elasticsearch query time vs. corpus size

30-day window, keyword `تهران` (~30% selectivity), 20 measured iterations per size.

These numbers are the **engine**, measured with the search cache bypassed. `bench:search`
unwraps `CachedSearchAdapter` deliberately: it issues the same query on every iteration,
so measuring through the cache would report the warm-up pass and then 19 cache hits — a
number that describes Redis while claiming to describe Elasticsearch. The cache's own
effect is measured separately, by `--herd` (see [Caching](#caching)).

| Documents | Index size | Matched | Engine avg | Engine p95 | Engine p99 | Wall avg | Wall p95 | Wall p99 | Index rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 10,000 | 6.3 MB | 236 | 3.75 ms | 14 ms | 14 ms | 6.36 ms | 17.4 ms | 17.4 ms | 5,325 docs/s |
| 100,000 | 61 MB | 2,440 | 2.55 ms | 5 ms | 5 ms | 4.86 ms | 7.2 ms | 7.2 ms | 15,743 docs/s |
| 1,000,000 | 607 MB | 24,525 | 1.25 ms | 2 ms | 2 ms | 3.06 ms | 3.8 ms | 3.8 ms | 19,041 docs/s |

**Query time is flat — in fact slightly better at 1M.** That is not the corpus
getting cheaper; it is the JVM being warmer and the index better merged by the time
the largest size runs, while the absolute numbers are small enough that warm-up noise
dominates. The honest reading is: **between 10k and 1M documents, aggregation time is
constant within measurement noise**, because a `size: 0` `date_histogram` costs
roughly the number of buckets returned (30), not the number of documents matched.

The ~2 ms gap between engine time and wall-clock time is HTTP transport plus JSON
deserialisation in PHP, and it is also flat — as it must be, since the response is
30 buckets regardless of corpus size.

#### Report generation time

Aggregation and workbook write, timed separately, with peak memory per run.

| Documents | Window | Rows | Matched | Query | Export | Total | Peak memory | File |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 10,000 | 1 day | 2 | 6 | 1 ms | 12 ms | 14 ms | 48.4 MB | 5.1 KB |
| 10,000 | 7 days | 8 | 56 | 1 ms | 2 ms | 5 ms | 48.4 MB | 5.2 KB |
| 10,000 | 365 days | 366 | 3,001 | 2 ms | 20 ms | 24 ms | 48.4 MB | 11.6 KB |
| 100,000 | 1 day | 2 | 79 | 1 ms | 3 ms | 5 ms | 52.4 MB | 5.1 KB |
| 100,000 | 7 days | 8 | 585 | 1 ms | 2 ms | 4 ms | 52.4 MB | 5.2 KB |
| 100,000 | 365 days | 366 | 29,741 | 2 ms | 20 ms | 24 ms | 52.4 MB | 11.9 KB |
| 1,000,000 | 1 day | 2 | 837 | 2 ms | 2 ms | 5 ms | 52.4 MB | 5.1 KB |
| 1,000,000 | 7 days | 8 | 5,711 | 1 ms | 2 ms | 5 ms | 52.4 MB | 5.2 KB |
| 1,000,000 | 365 days | 366 | **298,663** | **2 ms** | 20 ms | 24 ms | **52.4 MB** | 12.1 KB |

Three things this shows:

- **Query cost is independent of matches.** 298,663 matched documents cost the same
  2 ms as 6. This is the `size: 0` decision paying off, and it is the direct answer
  to "what if the user's data range is very large".
- **The system is export-bound, not query-bound.** At a 365-day window the workbook
  write is 20 ms against a 2 ms query — 10× the cost. If report latency ever needed
  optimising, the export is where to look, not Elasticsearch.
- **Memory is flat.** ~52 MB at every corpus size and every window length, which is
  the streaming-export claim measured rather than asserted.

#### API under concurrency

Staged ramp 10 → 25 → 50 → 100 → 200 VUs, 30 s plateau at each level, 3m30s total,
mixed read/write (one create per ten list requests) against the 1M-document corpus.

| Metric | Value |
|---|---|
| Requests | 102,368 |
| **Throughput** | **487 req/s** |
| **Average response time** | **59.0 ms** |
| Median | 26.6 ms |
| **P95** | **185.1 ms** |
| **P99** | **232.0 ms** |
| Max | 369.8 ms |
| Failed requests | **0.00%** (0 of 102,368) |
| Failed checks | 0 of 204,734 |
| Data received | 247 MB |

Per endpoint:

| Endpoint | Count | Avg | Median | P95 | P99 | Max |
|---|---:|---:|---:|---:|---:|---:|
| `GET /reports` | 92,981 | 59.2 ms | 26.6 ms | 185.6 ms | 233.0 ms | 369.8 ms |
| `POST /reports` | 9,386 | 57.7 ms | 26.8 ms | 180.5 ms | 224.4 ms | 342.6 ms |

Writes are no slower than reads, which is expected: the create path is a single
insert, and the read path is a paginated query plus resource serialisation.

#### API under increasing offered load

The ramp above answers "what happens with more concurrent users". This answers the
sharper question — "where does it saturate" — using a constant-arrival-rate
executor, which keeps offering the same load as latency rises. Fixed VUs would
quietly reduce throughput instead of exposing the limit. Read-only
(`GET /reports`), 45 s per rate, against the 1M-document corpus.

| Offered rate | Achieved | Avg | Median | P95 | P99 | Max | Failed | Dropped iterations |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 250/s | 249.0/s | 6.23 ms | 5.56 ms | 11.5 ms | 16.0 ms | 179 ms | 0.00% | 0 |
| 500/s | 497.9/s | 4.90 ms | 3.78 ms | 10.3 ms | 23.6 ms | 185 ms | 0.00% | 0 |
| 1,000/s | 995.8/s | 5.62 ms | 3.27 ms | 16.7 ms | 46.6 ms | 177 ms | 0.00% | 0 |
| 2,000/s | 1,990.5/s | 5.17 ms | 3.66 ms | 9.0 ms | 77.3 ms | 198 ms | 0.00% | 0 |

**The read path sustained 1,990 req/s with zero errors and zero dropped
iterations**, and the achieved rate tracked the offered rate at every step — so this
is not the saturation point, it is simply where the sweep stopped. Average latency
stayed flat at ~5 ms throughout; only P99 climbed (16 ms → 77 ms), which is the
expected shape as queueing begins to show in the tail while the median is untouched.

Zero dropped iterations at every rate matters: it means k6 was able to generate the
load it was asked for, so these numbers describe the server rather than the
generator.

The mixed ramp above reports higher latency (p95 185 ms) than this read-only sweep
at four times the request rate. That is not a contradiction — the ramp holds 200
concurrent VUs each running a create-and-list iteration with think time, so its
latency reflects concurrency depth, while this sweep reflects throughput at a
controlled arrival rate. They measure different things on purpose.

#### Limitations

These numbers are honest but bounded, and a reviewer should read them with the
following in mind:

- **Single machine, single node.** The load generator, the API, MySQL and
  Elasticsearch all share 20 cores. Absolute throughput is therefore *pessimistic* —
  k6 itself was competing for CPU. A separate load generator would report higher.
- **Single-node Elasticsearch, one shard.** No replication, no cross-node fan-out.
  Real cluster behaviour at 1M+ documents differs.
- **Synthetic text is not real prose.** Generated documents carry ~40 words of body
  text drawn from a fixed vocabulary, so the index is smaller and term dictionaries
  are simpler than for real news articles. The 21 real posts from `data.json` remain
  in the corpus as a sanity anchor, but the volume figures come from generated text
  and are optimistic relative to production.
- **The rate limiter was raised for the capacity run** (see Method). At the shipped
  default of 120 req/min per user, a single user cannot reach these numbers — by
  design.
- **The ramp is reported in aggregate**, not per plateau. k6's summary export is a
  single roll-up; per-stage percentiles would need a time-series output backend.
- **1M documents is where this stopped**, not where it broke. Nothing failed, no
  circuit breaker triggered, and the curve was still flat.

---

## Scope decisions

The task explicitly declares some things out of scope. Where it does, this project
does not build them — and says so rather than leaving the omission to be guessed at.

- **No role or permission system.** The task states authorization checks are not
  important, so there is no `spatie/laravel-permission`, no roles and no permission
  middleware. *Ownership* scoping is still enforced — a user only ever sees their own
  reports — because that is data integrity, not authorization. It lives in
  `ReportMediator` so the same rule applies from HTTP and from the queued job.
- **Email delivery is not fully implemented.** `MAIL_MAILER=log` writes the rendered
  message, attachment included, to the log. The task states delivery need not be
  complete and the appearance does not matter. Pointing `MAIL_MAILER` at SMTP is the
  only change needed to actually send.
- **Send day and time are not configurable.** The task waives both. Daily runs at
  06:00 and weekly on Saturday at 07:00, Tehran time.
- **Four routes beyond the three named.** `GET /reports/{id}`, `/runs`, `/run` and
  `/runs/{run}/download` were added because the deliverable — an Excel histogram —
  is otherwise only observable by waiting for the scheduler and reading the mail log.
  Without them the feature cannot be demonstrated or reviewed.
- **Missed windows are not backfilled.** A report that was down for three days
  resumes from the next boundary rather than replaying what it missed. Backfill is
  an explicit non-goal; the run history makes the gap visible.

---

## Production readiness

**What ships here is a single-host deployment.** `app`, `queue` and `scheduler` are the
same image with different commands, and all three bind-mount `./storage` from the host.
That shared filesystem is load-bearing: it is why per-container Passport keys happen to
match, and why a workbook written by `queue` is readable by `app`.

Running on more than one host removes it. This section states what changed to make that
safe, and what a real deployment would still need that this project deliberately does not
build.

### Changed, because it was broken

- **Migrations run in one place.** Every container's entrypoint used to run
  `migrate --force`, so replicas raced the same schema at boot. Migrating is now opt-in
  via `APP_RUN_MIGRATIONS`, default **off**, and runs through `migrate:locked`, which
  holds a MySQL named lock for the duration. A named lock is held by the *session*, so an
  instance killed mid-migration drops it when its connection dies — no stale lock to
  reap, and a crashed deploy cannot wedge the next one. Containers that do not migrate
  wait for the schema instead of crash-looping against an absent one. Seeding the
  personal access client moved behind the same gate, for the same reason.
- **Passport keys are injected, never generated.** `passport:keys` wrote a keypair per
  container. With a shared volume they collided harmlessly; without one, each instance
  signs with different keys and a token issued by one is rejected by the next —
  intermittent 401s that depend on which instance answered. In production the entrypoint
  now **refuses to start** unless `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` are
  supplied.
- **The reports disk can actually be object storage.** `config/filesystems.php` promised
  `REPORT_DISK_DRIVER=s3` worked while declaring no `key`, `secret`, `region` or
  `bucket` — so it could not. The application was already correct: both
  `HistogramExcelWriter` and the download controller go through `Storage::disk('reports')`
  and persist a disk-relative path. Only the configuration lied, and
  `ReportsDiskConfigTest` now asserts the disk carries every key the driver needs.
- **Logs go to stderr at `info`.** They were written to a file inside the container at
  `debug`, where no orchestrator collects them and nothing bounds their growth.
- **The image no longer carries a `.env`.** The developer's file — real `APP_KEY`
  and database credentials — was baked into every layer, because `.dockerignore`
  never excluded it. Besides shipping secrets, it defeated the guard below: a
  production container found that file, read `APP_ENV=local` from it, and booted on
  the developer's configuration instead of refusing. Containers now start from
  `.env.example` plus injected environment variables.
- **A misconfigured production container fails loudly.** It used to copy `.env.example`
  and generate its own `APP_KEY`. With `APP_ENV=production` and no mounted config or key,
  it now exits non-zero. This trades availability for correctness on purpose: one obvious
  failure at deploy time beats a fleet quietly serving wrong behaviour.

### Deployment changes this requires

- **`APP_RUN_MIGRATIONS=true` must be set on exactly one service.** `docker-compose.yml`
  sets it on a one-shot `migrate` service that the others wait for with
  `service_completed_successfully`; in Kubernetes the same flag makes an init Job the
  migrator. Set nowhere, nothing migrates and every container reports that as the reason
  it is waiting.
- **Production needs `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` before first deploy.**
  Read the existing `storage/oauth-*.key` files into those secrets and tokens already
  issued stay valid. Skip it and the container will not start.

### Not built, and why

These are real gaps. Inventing a retention policy or a metrics stack for a one-week
assessment is scope creep, so they are named rather than guessed at.

- **Retention.** Nothing prunes anything: Elasticsearch monthly indices, `report_runs`
  rows and stored workbooks all grow without bound. This is the item that actually breaks
  first — around twelve months in. The architecture already makes it cheap: retention is
  dropping an index rather than a delete-by-query, and workbooks are keyed by report so a
  bucket lifecycle rule (`AWS_REPORTS_BUCKET`) can expire them without touching anything
  else. Choosing the horizon is a product decision.
- **Elasticsearch durability and transport security.** `number_of_replicas` is `0`, so
  losing a node loses data and reds the cluster — correct for a benchmark on one machine,
  wrong anywhere else. The Compose node also runs with security disabled; production needs
  authentication and TLS, which `config/search.php` already has the settings for.
- **Redis topology.** Cache and queue share one instance on separate databases. That is
  fine until memory pressure: `maxmemory-policy` is instance-wide, so an eviction policy
  right for a cache will silently discard queued jobs, and no policy at all means an OOM
  kill takes the broker with the cache. They should be two instances — the queue with
  persistence and `noeviction`, the cache with `allkeys-lru`.
- **Observability.** `/api/v1/up` reports each dependency separately, which is the right
  shape, but there is nothing to alert *on*. The signals worth exporting are queue depth,
  `report_runs` failure rate, dispatch-to-completion latency, and Elasticsearch query
  time — plus a correlation ID threaded from request through job so one report's history
  is greppable.
- **Token lifetime.** No expiry is configured for personal access tokens, so a leaked
  token is valid indefinitely. `Passport::personalAccessTokensExpireIn()` is the one-line
  fix; it is not set because the task waives authorization concerns and a short expiry
  would make the Postman collection harder to use.

---

## Development

### Commands

```bash
make help              # every target, annotated

# environment
make build             # build the app image — RE-RUN AFTER CODE CHANGES
make up / down / restart / logs / shell

# application
make fresh             # migrate:fresh --seed (also fills Elasticsearch)
make index             # import data.json into Elasticsearch
make synthetic N=100000   # generate a synthetic corpus for benchmarking

# tests
make test              # unit + feature; needs the database from `make up`
make integration       # needs a live Elasticsearch
make ci                # the full gate — run before every push

# benchmark
make bench             # corpus sweep (long; flushes the index — re-run `make index` after)
make loadtest          # k6 ramp scenario
make loadtest-steady   # k6 fixed-arrival-rate scenario
```

> The Docker image **bakes the source** and caches routes at boot. After changing
> code, `make build && make restart` — otherwise the container keeps serving the
> previous build, which surfaces as a confusing 404 on new routes.

### Architecture conventions

`CLAUDE.md` is the full guide. The short version:

- Controllers are thin: build a DTO, call one service method, return through
  `successResponse()` / `failureResponse()`. Never construct a `JsonResponse` by hand.
- Services never touch Eloquent — that is the repository's job. Every repository
  interface must be bound in `app/Providers/BaseRepositoryProvider.php`.
- Nothing outside `app/Adapters/Elasticsearch/` touches the search client.
- Every user-facing string goes through `trans('messages.*')`, in both `lang/fa` and
  `lang/en`.
- `declare(strict_types=1)` everywhere; backed enums rather than class constants.
- No `env()` outside `config/`, so `config:cache` is always safe.

### Quality

`make ci` runs `composer validate --strict`, Pint, PHPStan (Larastan, **level 6**,
no baseline and no suppressions), Rector as a report, and the Unit + Feature suites.
The same gate runs in `.github/workflows/ci.yml`, which needs a `COMPOSER_SSH_KEY`
secret to reach the two private `mehrand/*` packages.

### Testing

**288 tests / 1,171 assertions** across Unit (100) and Feature (188), plus 8
integration tests (17 assertions) against a live cluster — 296 in total.

Test configuration lives in **`.env.testing`**, which is committed and which
Laravel loads *instead of* `.env` whenever `APP_ENV=testing` — so it is
self-sufficient, APP_KEY included. `phpunit.xml` sets only `APP_ENV`; anything
duplicated there would silently win, because PHPUnit sets its variables before
Dotenv runs and Dotenv never overwrites an existing one.

That same precedence is what lets one committed file serve three environments:
the values in it target the Docker stack's forwarded ports, while CI overrides the
database host, port and credentials through the workflow's `env:` block.

Tests run on the **host**, like `lint`, `analyse` and `rector` — not inside the app
container, whose entrypoint runs `config:cache`, and a cached config makes Laravel
skip environment loading altogether. `make up` still has to be running, since the
suite connects to the stack's database on its forwarded port.

`SEARCH_DRIVER=fake` binds an in-memory search double in the test environment, so
unit and feature tests need no infrastructure beyond that database. Only `tests/Integration` talks to a
real cluster — it asserts the things the double cannot reproduce: the Persian
analysis chain, the strict mapping, and the shape of a real `date_histogram`
response. It is excluded from `make test` and from CI; run it with `make integration`.

Several of the defects fixed in this project were found by writing these tests
rather than by review — the timezone drift, the dispatcher hot-loop, the
unretryable failed window, and a rate-limit rejection that returned 500 instead of
429. Where a test and the code disagreed, the code was fixed.

### Configuration

Every value the application reads lives in `config/` and is reached through
`config()`. `.env.example` documents each key; the ones worth knowing:

| Key | Meaning |
|---|---|
| `SEARCH_DRIVER` | `elasticsearch` or `fake` (tests) |
| `ELASTICSEARCH_HOSTS` | Comma-separated cluster hosts |
| `ELASTICSEARCH_INDEX_ALIAS` | Read alias the monthly indices sit behind |
| `ELASTICSEARCH_CHUNK_SIZE` | Documents per bulk request |
| `REPORT_TIMEZONE` | Timezone whose calendar days define histogram buckets |
| `REPORT_DISK_DRIVER` | Filesystem driver for generated workbooks: `local` or `s3` |
| `API_RATE_LIMIT` | Requests per minute per authenticated user |
| `OCTANE_WORKERS` | Swoole worker count |
| `ES_JAVA_OPTS` | Elasticsearch heap; raise for the benchmark sweep |

### Layout

```
app/
  Adapters/        Contracts/ (SearchAdapterInterface + value objects), Elasticsearch/, Fake/
  Console/Commands/ posts:setup|index|synthetic|flush, reports:dispatch, bench:search|report
  DTO/             Contracts/ + one folder per domain
  Enums/           backed enums — no class constants for domain values
  Exceptions/      app-local renderers mapped in config/exceptions.php
  Http/            Controllers/Api/V1, Requests, Resources, Filters
  Jobs/            GenerateReportJob
  Mail/            ReportReadyMail
  Mediators/       business-rule guards (ownership)
  Repositories/    Contracts/ (interfaces) + implementations
  Services/        Report/, Search/, Contracts/, Traits/
  Support/         JsonArrayStreamReader
docker/            Dockerfile, entrypoint, supervisord, php.ini
loadtest/          k6 scenarios and committed raw results
postman/           collection + environment
CLAUDE.md          architecture, conventions and the spec-driven workflow
data.json          the supplied corpus: 21 Persian news posts
```
