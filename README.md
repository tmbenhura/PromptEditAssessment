# Pixelforge Studio

A Laravel 13 + Inertia (Vue 3) app where signed-in users generate images with the
[Pixelforge](guidance/pixelforge-api-docs.md) API, see what each one will cost before
they submit, and are charged exactly what it cost Pixelforge plus 25%.

## Running it

```bash
composer setup                 # install, .env, key, migrate, build assets
# set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET in .env
composer dev                   # web server, queue worker and Vite
php artisan schedule:work      # reconciliation + pruning (needed if `dev` doesn't run it)
php artisan test               # Pest; calls the real Pixelforge API, as the brief requires
php artisan users:make-admin you@example.com   # unlock the operator reports
```

Pixelforge must be able to reach `POST /webhooks/pixelforge`, so expose local dev with a
tunnel (Expose, ngrok, Cloudflare Tunnel) and set that URL on your API key in the
Pixelforge dashboard. If PHP on your machine has no CA bundle, set
`PIXELFORGE_VERIFY_SSL=false` locally (never in production).

## How it works

**Generating.** `POST /generations` records the generation, then queues a
`SubmitGeneration` job; the request returns immediately. The job calls Pixelforge with a
per-generation `Idempotency-Key`, so a retry after a timeout can never create or bill a
second image. `429` and `503` responses are retried through the queue, honouring
`Retry-After` as a floor over a configurable backoff, up to a configurable attempt cap.
At most `PIXELFORGE_SUBMISSION_MAX_CONCURRENT` (3) submissions run at once.

The daily quota (500 generations per UTC day) is different: Pixelforge sends no
`Retry-After` for `daily_limit_exceeded`, and retrying sooner is pointless. The generation
stays queued with its credits reserved, `submit_after` is set to the next UTC midnight
(plus `PIXELFORGE_SUBMISSION_DAILY_LIMIT_BUFFER` seconds), and a fresh job is scheduled for
then with a full attempt budget. The dashboard shows when it will start, and the
reconcile command leaves it alone until that time has passed.

**Pricing and money.** All amounts are exact decimal strings (`decimal(18,9)`) handled with
bcmath, never floats. Actual cost is computed from the `usage` and the `rates` Pixelforge
fixed for that generation: `tokens × rate ÷ 1,000,000` per token type, summed exactly,
then `× 1.25` for the user. Provider cost is never rounded. The user price is exact to
nine decimals (any finer fraction of a nanodollar is dropped). Failed generations are
recorded at zero. Pixelforge bills a generation on the UTC day it *completed*, so a charge
transaction carries `occurred_at` (that completion time) alongside `created_at` (when we
recorded it, possibly after midnight if the webhook was late). The upfront figure is an estimate (a range plus a typical value); the
text and reasoning tokens are only known once the generation completes.

**Balances.** Users start with $20 and can "buy" test credits. While a generation is
queued or processing, its **maximum** possible cost is held against the balance, so
concurrent submissions can't overspend. Check-and-hold happens under a row lock, and when
the generation finishes the hold is released and the real cost deducted in one
transaction. Failures release the hold and cost nothing.

**Webhooks.** `POST /webhooks/pixelforge` verifies the `Pixelforge-Signature` HMAC in
constant time against the raw body and rejects timestamps more than 5 minutes old. It
sits outside the `web` middleware group (no session or CSRF). It does the minimum before
replying: verify, log the event, return `204`. The event is then applied with `defer()`,
after the response, so Pixelforge's 10-second window is never at risk. Every authenticated
event is logged to `webhook_events` (one row per event id; redeliveries bump a counter and
record `Pixelforge-Delivery-Attempt`), and
`webhook-events:prune` deletes old ones past `PIXELFORGE_WEBHOOK_EVENTS_RETENTION_DAYS`.
A generation never moves back from `succeeded` or `failed`, so out-of-order events are
harmless, and an event that arrives before the create call returns is matched through the
`app_generation_id` metadata. A charge is applied at most once: checked under a lock, and
backed by a unique `(generation_id, type)` index on `transactions`.

**Missed webhooks.** Delivery isn't guaranteed, and because the reply is sent before the
event is applied, Pixelforge won't retry one whose deferred work fails. That event stays
on record as `received`/`failed` with its payload, and `generations:reconcile` (every
minute) replays it. It also polls in-flight generations that have been quiet for a while, and resubmits
ones whose submission job was lost. Without it a missed webhook would hold a user's
credits indefinitely.

**History.** The dashboard shows live status (polling only while something is in flight),
estimated and actual cost and the image, and an "Image expired" state once Pixelforge's
24-hour URL stops working.

**Operator report** (`/reports`, admins only). Per UTC day and per user: what users were
charged, what Pixelforge charged, and profit, reconciled against `GET /v1/usage`.
Generations are grouped by the day they *completed*, as Pixelforge bills them.

## Deploying (Laravel Cloud or any host)

1. Environment: `APP_KEY`, a real database, `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`
   (the secret belonging to *that* API key), and `QUEUE_CONNECTION=database` (or Redis).
   Use a separate Pixelforge key per environment.
2. Run a **queue worker** (`php artisan queue:work`) and the **scheduler**
   (`php artisan schedule:run` every minute). Generations never reach Pixelforge without
   the worker, and credits are never released after a missed webhook without the scheduler.
3. In the Pixelforge dashboard set the key's webhook URL to
   `https://<your-host>/webhooks/pixelforge` and send a test event.
4. If the host can't send email, set `REQUIRE_EMAIL_VERIFICATION=false` so sign-ups aren't
   blocked waiting for a verification link.
5. `php artisan migrate --force`, then `php artisan users:make-admin <email>`.

## Configuration

Everything tunable is an environment variable, listed in `.env.example`: submission
attempts, backoff and concurrency, reconcile intervals, webhook log retention, and TLS
verification.

## Design notes and open questions

- **Rates in estimates.** Estimates and the reserved maximum use the rates from
  `GET /v1/pricing` (cached 10 minutes). The charge always uses the rates Pixelforge
  returned for the generation itself.
- **Token tables.** The per-quality token counts are constants in `CostCalculator` and
  the Vue estimator, matching the published docs. If Pixelforge changes them they need
  updating in both places.
- **Lost create response.** If every attempt to create a generation fails, it is marked
  failed at no cost. A response lost *and* never recovered by an idempotent retry could
  leave an accepted generation un-billed locally; the reconcile command only resubmits
  generations that are still queued.
