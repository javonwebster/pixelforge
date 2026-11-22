# PixelForge

[![CI](https://github.com/javonwebster/pixelforge/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/javonwebster/pixelforge/actions/workflows/ci.yml)

A Laravel + Vue + Inertia app that sends image generations to [Pixelforge](https://pixelforge.promptedit.dev/docs), charges users Pixelforge's cost plus 25%, and stays correct when Pixelforge fails, overloads, or delivers webhooks twice and out of order. Every generation is charged exactly once, at the exact cost; failed generations are free.

**Live:** https://pixelforge.bebettuh.com (sign-up is closed; logins are sent with the application).

Stack: Laravel 13, PHP 8.4, Vue 3, Inertia 3, Tailwind 4, TypeScript, Postgres 16, AWS S3. Tests run on Postgres in CI and gate every deploy.

---

## 1. How money works

**All money is integer nanodollars** (1 USD = 1,000,000,000) in `bigint` columns. That is exactly the precision of Pixelforge's nine-decimal `cost_usd`, so provider costs are stored without rounding and SQL sums are exact. No float ever touches money: Pixelforge's rate strings (`"12.50"`) go straight into `brick/math` `BigDecimal`.

**One calculator** ([`PricingCalculator`](app/Services/Pricing/PricingCalculator.php)) produces the live estimate on the Generate page, the maximum that's held, and the final charge. The number shown is the number held.

```
cost  = Σ tokens × rate ÷ 1,000,000        (USD, rates per 1M tokens)
price = cost × 125 ÷ 100                    (rounded half up to the nanodollar)
```

The cost sum is computed in exact decimals and converted to nanodollars once, at the end; if it isn't a whole number of nanodollars it **throws** rather than round someone's money. With today's rates every cost is a multiple of 2,500 nanodollars, so the markup is always exact; the half-up rule exists for future rates.

**Estimate, maximum, actual**

| Token type  | Estimate                                   | Maximum (what's held)       | Actual (after success)     |
| ----------- | ------------------------------------------ | --------------------------- | -------------------------- |
| Input text  | `ceil(characters / 4)`, Unicode characters | estimate × 1.15, rounded up | `usage.input_text_tokens`  |
| Input image | 765 × reference images                     | same                        | `usage.input_image_tokens` |
| Output      | Fixed by size and quality                  | same                        | `usage.output_tokens`      |
| Reasoning   | Midpoint of the quality's range            | Top of the range            | `usage.reasoning_tokens`   |

The docs' own maximum example (high, 1536×1024, 400 characters, 2 reference images) comes out at exactly **$0.373875**, and it's a unit test.

- **Estimate rates** come from `GET /v1/pricing` (cached an hour, documented rates as a fallback). **Actual costs** always use the rates stored on that generation, because Pixelforge fixes them at creation.
- **Users see whole cents** (`$0.07`); hovering shows the exact figure (`$0.072500000`). Only the display rounds. Admin figures keep all nine decimals so they can be compared with `/v1/usage` digit for digit.

**The ledger.** A balance is the sum of the user's [`ledger_entries`](database/migrations/2026_11_22_000003_create_ledger_entries_table.php). Nothing else stores a balance that could drift. Only [`LedgerService`](app/Services/LedgerService.php) writes it.

| Event                         | Entry                                |
| ----------------------------- | ------------------------------------ |
| Buy credits                   | `purchase` +amount                   |
| Submit                        | `hold` −maximum                      |
| Success                       | `release` +maximum, `charge` −actual |
| Failure, rejection, giving up | `release` +maximum                   |

- **Unique (`generation_id`, `type`)** is the database-level guarantee that a generation is held, released and charged at most once each. A check constraint enforces each type's sign.
- **Submitting** locks the user's row (`SELECT … FOR UPDATE`), checks the available balance against the maximum, then writes the generation and its hold in one transaction. Two simultaneous submits can't both pass the check; a test forks two processes to prove it.
- **Failed generations are free** because no `charge` row is ever written for them.
- **Users can read the ledger.** The Credits page lists every entry newest first ("Credits purchased", "Held for a generation", "Hold returned", "Charged for a generation") with the generation it belongs to and the balance after it, filterable by date and amount. The running balance is a window sum over the whole ledger, so it stays true on a filtered page.
- **Buying credits** (fake: no payment) offers $5, $10, $20 or $50, or any amount from $1 to $500, parsed as an exact decimal string, never a float.

---

## 2. How it stays correct

```
Generate form ──► row + hold (one transaction) ──► SubmitGeneration job ──► POST /v1/generations
                                                          │ 202 (or an idempotent replay)
                                                          ▼
Webhook (signed, deduped) ────────────────────►  GenerationUpdater  ◄──── pixelforge:reconcile (every minute)
                                                 row lock · rank check
                                                 release + charge, once
```

Every status change after creation goes through one class, [`GenerationUpdater`](app/Services/GenerationUpdater.php): the submit response, webhooks and the poller all feed it. It locks the row, only moves forward, and writes the ledger entries.

**State machine**

| Status              | Rank        | Set by         | Meaning                                                        |
| ------------------- | ----------- | -------------- | -------------------------------------------------------------- |
| `pending`           | 0           | Submit         | Created locally, not yet accepted                              |
| `submission_failed` | 0           | Job, giving up | Outcome unclear (timeout, no response): Pixelforge may have it |
| `queued`            | 1           | Pixelforge     | Accepted                                                       |
| `processing`        | 2           | Pixelforge     | Running                                                        |
| `succeeded`         | 3, terminal | Pixelforge     | Billed                                                         |
| `failed`            | 3, terminal | Pixelforge     | Not billed                                                     |
| `rejected`          | 3, terminal | Job            | Pixelforge definitely created nothing                          |

A status applies only if its rank is higher than the current one, and nothing changes a terminal status, so a late or duplicate event can't undo a result. `submission_failed` sits at rank 0 on purpose: if Pixelforge later reports the generation, it moves forward and is billed like any other.

**Idempotent submits.** The local UUID is both the `Idempotency-Key` and `metadata.local_id`. The request body is built from the stored row and encoded once, so every retry is byte-identical: Pixelforge replays the original instead of creating a second one, and `409 idempotency_key_reused` can't happen. A replay returns the generation _as it is now_, possibly already succeeded, which is why the submit response goes through the same updater as webhooks.

**Every submit answer is handled** ([`SubmitGeneration`](app/Jobs/SubmitGeneration.php)):

| Pixelforge answer                       | What happens                                                                                                    | User sees                    |
| --------------------------------------- | --------------------------------------------------------------------------------------------------------------- | ---------------------------- |
| `202`                                   | Saved and passed to the updater                                                                                 | Queued                       |
| `429` concurrency / rate limit          | Retry after `Retry-After`; checks the local in-flight count first so it doesn't spend a request on a sure `429` | Waiting for a slot           |
| `503`, timeout, no response             | Retry with backoff (2, 4, 8 … 60 s), same key                                                                   | Pixelforge is busy, retrying |
| `429` daily limit, `400` content policy | `rejected`, hold released                                                                                       | Failed, $0                   |
| `422`, `401`                            | `rejected`, hold released, logged as a bug                                                                      | Failed, $0                   |
| `409`                                   | `submission_failed`, logged as critical                                                                         | Failed, $0                   |

The job gives up after 5 minutes. If the last answer was a clean refusal, nothing exists at Pixelforge and the row is `rejected`; after a timeout it's `submission_failed`, and reconcile keeps looking for it for 24 hours.

**Webhooks** ([`PixelforgeWebhookController`](app/Http/Controllers/PixelforgeWebhookController.php)):

- **Signed:** HMAC-SHA256 of `<t>.<raw body>` with the `whsec_` secret, constant-time compare, 5-minute tolerance either way. Anything else gets a `400` and nothing is stored.
- **Deduped and applied in one transaction:** insert the event (unique `event_id`), apply it, commit. A duplicate hits the unique index and gets a `200` with nothing else applied; it's counted on the stored event and logged, and History shows a blue "Duplicate webhook ignored" badge next to the result. If applying throws, the insert rolls back too and Pixelforge's retry is processed as new. With two separate steps, a crash between them would record the event without applying it, and the retry would be discarded as a duplicate.
- **Before the POST returns:** a webhook can arrive first, so it's matched by `metadata.local_id`, not only by Pixelforge's id.
- **Outside the `web` middleware group:** no session, cookies or CSRF.

**Reconcile** ([`pixelforge:reconcile`](app/Console/Commands/ReconcileGenerations.php), every minute), because delivery isn't guaranteed:

1. Re-applies stored webhook events that matched nothing yet.
2. Polls `queued`/`processing` generations with no news for 60 seconds (at most 20 per run).
3. Finds `submission_failed` rows from the last day with one read-only `GET /v1/generations` call, matching `metadata.local_id`.

**One automatic retry of `internal_error`.** Pixelforge says `internal_error` is on its side and usually fixed by retrying, and failed generations are never billed, so one retry can't double-charge. It's a **new row** linked by `retry_of_id`, with its own hold and key; reusing the row would let a late duplicate webhook from the first attempt fail the retry. A partial unique index allows at most one retry per generation. `generation_failed` is never retried: the same prompt would fail again.

**Rate limits.** Pixelforge allows 60 requests a minute per account, across all keys. A shared sliding-window throttle (in the cache, under a lock) stops at 55, leaving room for the local key, and pauses when Pixelforge's `X-RateLimit-Remaining` reaches 0.

**Admin report** ([`/admin/report`](app/Services/AdminReport.php), admins only). For each UTC day and user: generations, credit spend, provider cost and profit, exact to nine decimals. Pixelforge's own `GET /v1/usage` cost sits beside ours, with a match marker per day and for the range. Generations count on the UTC day of Pixelforge's `completed_at`, which is how `/v1/usage` bills, so one submitted at 23:59 and finished at 00:01 lands on the second day. Days only Pixelforge billed still show, as mismatches. Up to 92 days per range, Pixelforge's limit; usage is cached for a minute because the call counts against the rate limit. A stacked bar per account shows provider cost and profit (together, credit spend), and every column explains itself on hover or tap.

**Image copies** ([`ProcessGeneratedImage`](app/Jobs/ProcessGeneratedImage.php)). Pixelforge's image links stop working 24 hours after completion, so after a success commits, a queue job downloads the PNG (only from Pixelforge's host), converts it with ffmpeg to a full-size WebP and a 384px thumbnail, and stores both in a private S3 bucket (`us-east-2`). History and downloads then use our copy through 10-minute signed links, cached for 8 minutes so the 3-second poll doesn't re-download images. The job never touches money, is unique per generation, and retries on failure; reconcile re-queues any succeeded generation still missing a copy while its link is valid. Without a bucket configured (local development), the same disk is a private local folder.

---

## 3. Decisions and trade-offs

The brief leaves these open on purpose. Each has a decision and a reason.

| Open point                      | Decision                                                                                                                                  | Why                                                                                                                                    |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| What "estimate" means           | Show an **estimate** (midpoint reasoning) and a **maximum**                                                                               | Honest about the range; the maximum is what the hold needs                                                                             |
| Fractions of a cent             | Store exact nanodollars; **users see whole cents**, exact on hover; admins see 9 decimals                                                 | Never lose money to rounding; cents are what users expect; admin figures match `/v1/usage` exactly                                     |
| Low-balance message             | Rounds what's needed **up** and the balance **down**                                                                                      | So it can never read "needs $0.09, balance $0.09"                                                                                      |
| Markup rounding                 | Half up to the nanodollar                                                                                                                 | Exact with today's rates; the rule exists for future ones                                                                              |
| Retry after `internal_error`    | Once, automatically, as a new linked row; `generation_failed` never                                                                       | Free if it fails again; docs say retrying usually works                                                                                |
| Submit errors before acceptance | `rejected`, free, with a plain reason                                                                                                     | Nothing was created at Pixelforge                                                                                                      |
| Response lost after acceptance  | Retry with the same key; give up after **5 minutes** as `submission_failed`; reconcile looks for it for 24 h and bills it if it succeeded | Pixelforge billed us and the user gets the image; our records stay in step with `/v1/usage`. Five minutes keeps "busy, retrying" short |
| Balance can't cover the maximum | Refuse before submitting, on the page and on the server, with the same message                                                            | The maximum is the most Pixelforge can bill                                                                                            |
| Actual cost above the hold      | Charge it anyway; balance may dip slightly negative; logged                                                                               | It's what Pixelforge billed. Shouldn't happen                                                                                          |
| Which day a cost belongs to     | UTC day of Pixelforge's `completed_at`                                                                                                    | How `/v1/usage` bills                                                                                                                  |
| Rates for the actual cost       | The generation's own `rates`                                                                                                              | Fixed at creation                                                                                                                      |
| Rates for the retry's estimate  | The failed generation's own rates                                                                                                         | No pricing HTTP call while the webhook holds a row lock                                                                                |
| Live status                     | Inertia `usePoll` every 3 s, partial reload of `generations` and `balance`, only while something is unfinished                            | Simple and inside the stack; Reverb is the next step                                                                                   |
| Sign-up                         | Closed; seeded admin and demo accounts                                                                                                    | Public URL, account-wide daily quota, public repo                                                                                      |
| Deleting an account             | Soft delete                                                                                                                               | Money records restrict hard deletes, so the admin report keeps matching `/v1/usage`                                                    |
| Reference images                | Links only (up to 4, http/https), shown as links, never `<img>`                                                                           | It's what the API accepts. Pixelforge bills each but doesn't download them, so uploads would cost users money for nothing              |
| Downloads                       | Streamed through the app, owner only, from Pixelforge's host only                                                                         | Browsers ignore `download` on cross-origin links; the host check stops the server fetching arbitrary URLs                              |
| Environments                    | One deployed environment, no staging                                                                                                      | One deployer, one night. Staging and tag promotion are in "What I'd do next"                                                           |

---

## 4. Testing

```bash
docker compose -f compose.local.yaml up -d    # Postgres 16 on 127.0.0.1:5433
php artisan test                               # PHPUnit, against Postgres (never SQLite)
vendor/bin/pint --test && vendor/bin/phpstan analyse
npm run check && npm run types:check
```

296 tests. Every money rule and every Pixelforge failure mode has one, using `Http::fake()` and signed fake webhooks:

- **Pricing:** the docs' maximum example exactly; the worked examples; half-up rounding through a synthetic rate; an inexact rate throws; Unicode prompts count characters, not bytes.
- **Webhooks:** valid, wrong secret, tampered body, missing header, stale or future timestamp; the same event twice (one charge); a throw while applying (no event row, so the redelivery is applied); `processing` after `succeeded`; a webhook before the submit response; `internal_error` retried once, never twice, and not by a late duplicate.
- **Submit:** every row of the error table, including `503` then `202` (two calls, same key, same bytes, one generation) and giving up both ways.
- **Concurrency:** two submits at once with credit for one, and two `succeeded` deliveries at once. Both fork real processes against committed rows. Removing the row locks makes them fail.
- **Image copies:** the real ffmpeg conversion (CI installs it), storing and serving our copy, retrying a failed download, never touching money, never fetching outside Pixelforge's host, and the reconcile backfill.
- **Admin report:** grouping by Pixelforge's completion day (including across midnight UTC), match and mismatch, Pixelforge unreachable, the 92-day limit, admins only.
- **Reconcile, balances, access** (another user's generation is a 404; `/register` is a 404), **status labels** row by row, and the seeder.

**Live checks** on the deployed app, against Pixelforge's test keywords:

| Check          | Result                                                                                                   |
| -------------- | -------------------------------------------------------------------------------------------------------- |
| Plain prompt   | Succeeded in about 5 s; balance fell by exactly the charge ($0.074734375)                                |
| `[fail]`       | Failed, "Pixelforge couldn't make an image for this prompt", $0, balance unchanged                       |
| `[blocked]`    | Failed, "Blocked by content policy", $0                                                                  |
| `[slow]`       | About 70 s in Processing, updating live, then Succeeded                                                  |
| `[duplicate]`  | One charge; balance exact to the nanodollar; History marks the ignored repeat deliveries                 |
| Five at once   | Three running, two "Waiting for a slot", never more than three in flight; all five succeeded             |
| `[overloaded]` | "Pixelforge is busy, retrying", then Failed at $0 after the 5-minute window                              |
| Admin report   | Every day's cost matches `GET /v1/usage` for the production key, to the nanodollar                       |
| Image copies   | Every succeeded image copied to S3 within two minutes of deploying; History and downloads serve the WebP |

---

## 5. Running locally

Needs PHP 8.4, Composer, Node 22 and Docker.

```bash
docker compose -f compose.local.yaml up -d    # Postgres 16 on 127.0.0.1:5433
composer setup                                 # install, .env, key, migrate, build
composer dev                                   # server, queue worker, logs, Vite
```

In `.env`, set:

- `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`: the **local** key's values (each key has its own webhook URL and secret).
- `SEED_ADMIN_*` and `SEED_DEMO_*`, then `php artisan db:seed` to create the two accounts with $5 each.
- `PIXELFORGE_SUBMIT_GIVE_UP_MINUTES` (optional): shorten it to watch `[overloaded]` give up sooner.

Webhooks need a public HTTPS URL, so point a tunnel at `localhost:8000` and register `https://<tunnel>/webhooks/pixelforge` for the local key. Run the scheduler (`php artisan schedule:work`) for reconcile.

---

## 6. Deployment

One Docker image, three containers, on a single Docker host behind Caddy (TLS):

| Container      | Command                            | Notes                                                          |
| -------------- | ---------------------------------- | -------------------------------------------------------------- |
| `pf-web`       | nginx + php-fpm                    | Migrates and caches config on start; healthcheck on `/up`      |
| `pf-queue`     | `php artisan queue:work --tries=0` | Each job decides its own retries; runs ffmpeg for image copies |
| `pf-scheduler` | `php artisan schedule:work`        | Runs `pixelforge:reconcile` every minute                       |

Queue and cache use Laravel's `database` drivers on the shared Postgres 16, so nothing outside the stack (no Redis) is needed. The workers start only once `pf-web` is healthy, so a release's migrations always run first.

**CI/CD** ([`ci.yml`](.github/workflows/ci.yml)): every push runs Pint, Larastan, frontend lint and types, and PHPUnit against a `postgres:16` service, then builds the image. On `main`, and only when all of that is green, it pushes to GHCR, deploys over SSH, waits for `pf-web` to be healthy, rolls back to the previous image digest if it isn't, and checks `/up` from outside. A red build never reaches the live app. Actions are pinned by commit SHA.

**Single environment, deliberately:** one app, one deployer, one night. See "What I'd do next".

**Why not Laravel Cloud or Vapor:** the image-processing job needs ffmpeg in the worker. On AWS the same three containers map directly onto ECS: one task definition per role (web behind an ALB; queue and scheduler as services without a load balancer, the scheduler at one task), RDS for Postgres, and secrets from SSM Parameter Store instead of an `.env`.

---

## 7. What I'd do next

- **Live updates over websockets** (Reverb) instead of polling.
- **Staging plus tag promotion:** deploy `main` to staging, promote the exact same image to production on a tag.
- **Alerting on drift:** a daily job comparing our provider cost with `/v1/usage`, and alerts on `submission_failed` and actual-above-hold warnings.
- **Reference image uploads with a real provider:** store uploads in S3 and pass signed links as `reference_images`. Not worth it here: Pixelforge bills them but never reads them.
- **Product analytics in ClickHouse:** send each finished generation (tokens, cost, price, size, quality, time to complete, failure code) for questions like quality mix, failure rate by prompt length and margin by size. Postgres stays the record for money. Nothing is built.
