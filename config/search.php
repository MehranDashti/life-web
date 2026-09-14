<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Search (Elasticsearch) configuration
|--------------------------------------------------------------------------
|
| Nothing in app/ may call env() — every value the application reads lives here
| and is reached through config(), so `php artisan config:cache` stays safe.
|
*/

return [

    /*
     | Which SearchAdapterInterface implementation AppServiceProvider binds.
     | `fake` is used by the test suite so unit and feature tests never require
     | a running Elasticsearch.
     */
    'driver' => env('SEARCH_DRIVER', 'elasticsearch'),

    'elasticsearch' => [

        /* Comma-separated list, e.g. "http://es1:9200,http://es2:9200". */
        'hosts' => array_values(array_filter(
            array_map(trim(...), explode(',', (string) env('ELASTICSEARCH_HOSTS', 'http://localhost:9200'))),
        )),

        'username' => env('ELASTICSEARCH_USERNAME'),
        'password' => env('ELASTICSEARCH_PASSWORD'),
        'api_key' => env('ELASTICSEARCH_API_KEY'),
        'ca_bundle' => env('ELASTICSEARCH_CA_BUNDLE'),

        /*
         | The alias every read and write goes through. Concrete indices are
         | time-based (posts-YYYY.MM) and sit behind it, so a month can be
         | closed, frozen, or dropped without touching application code.
         */
        'alias' => env('ELASTICSEARCH_INDEX_ALIAS', 'posts'),
        'index_pattern' => env('ELASTICSEARCH_INDEX_PATTERN', 'posts-*'),
        'template_name' => env('ELASTICSEARCH_TEMPLATE_NAME', 'posts-template'),

        'number_of_shards' => (int) env('ELASTICSEARCH_SHARDS', 1),
        'number_of_replicas' => (int) env('ELASTICSEARCH_REPLICAS', 0),

        /* Documents per bulk request. Higher = fewer round trips, more memory. */
        'chunk_size' => (int) env('ELASTICSEARCH_CHUNK_SIZE', 1000),

        'retries' => (int) env('ELASTICSEARCH_RETRIES', 2),

        /* Seconds. Report queries are aggregations, so this is generous by design. */
        'request_timeout' => (int) env('ELASTICSEARCH_REQUEST_TIMEOUT', 30),

        /* Errors kept per failed bulk chunk before truncating the log payload. */
        'max_reported_bulk_errors' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search result cache
    |--------------------------------------------------------------------------
    |
    | Search is only reached during report generation, so the load concentrates
    | entirely on the scheduler tick: every due report issues its own
    | aggregation, and many are identical because different users track
    | overlapping keywords over the same window. Caching collapses those into one
    | engine call.
    |
    | This is about load, not latency. The benchmark measures the aggregation at
    | 1-2ms flat from 10k to 1M documents, so there is no single-request speed-up
    | to be had here.
    |
    */

    'cache' => [
        'enabled' => (bool) env('SEARCH_CACHE_ENABLED', true),

        /* Must be a store that survives across processes; the queue workers and
         | the web tier have to share it for the collapse to happen at all. */
        'store' => env('SEARCH_CACHE_STORE', 'redis'),

        /* A backstop only. Correctness comes from the corpus version, which
         | invalidates everything the moment the index is written to. */
        'ttl' => (int) env('SEARCH_CACHE_TTL', 900),

        'count_ttl' => (int) env('SEARCH_CACHE_COUNT_TTL', 60),
    ],

    /*
     | Histogram defaults. Report buckets are calendar days in this timezone —
     | the data is Persian news, so Tehran local days are the meaningful unit,
     | not UTC days.
     */
    'histogram' => [
        'timezone' => env('REPORT_TIMEZONE', 'Asia/Tehran'),
        'calendar_interval' => 'day',
    ],
];
