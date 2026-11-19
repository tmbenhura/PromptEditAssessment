# Pixelforge API reference

Pixelforge generates images from text prompts. Generation is asynchronous: you create a generation, it is queued and processed in the background, and you learn the result from a webhook or by polling. Every request and response body is JSON.

Base URL:

Base URLCopy

```
https://pixelforge.promptedit.dev/v1
```

- Timestamps are ISO 8601 strings in UTC, e.g. `2026-11-04T14:03:11Z`.
- Ids are prefixed by type: `gen_` for generations, `evt_` for webhook events.
- Money is always an exact decimal string in US dollars, never a float, e.g. `"0.046125000"`.

Pixelforge is simulated

There is no real model. Images are abstract placeholders at the requested size, captioned with your prompt, and nothing costs real money. Everything else (pricing, limits, timing, failures and webhooks) behaves like a real provider.

## Authentication

Authenticate with an API key, sent as a bearer token. [Sign up](https://pixelforge.promptedit.dev/register), then create a key in the dashboard. A key is shown once, when you create it, so store it somewhere safe. Revoked keys stop working immediately.

Each key has its own [webhook URL and signing secret](https://pixelforge.promptedit.dev/docs#webhooks) and its own [usage report](https://pixelforge.promptedit.dev/docs#usage). Use one key per environment (for example one for local development and one for your deployed app), so each environment only receives its own events and can reconcile its own costs.

bashCopy

```
curl https://pixelforge.promptedit.dev/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K \
  -H "Authorization: Bearer $PIXELFORGE_API_KEY"
```

Requests without a valid key get `401` with the code `invalid_api_key`.

## Errors

Errors use conventional HTTP status codes and always have the same shape. Validation errors also name the first invalid parameter (`param`) and list every problem (`errors`). Branch on `code`; `type` groups related codes.

jsonCopy

```
{
  "error": {
    "type": "invalid_request_error",
    "code": "validation_failed",
    "message": "The selected size is invalid.",
    "param": "size",
    "errors": {
      "size": ["The size must be one of: 1024x1024, 1536x1024, 1024x1536."]
    }
  }
}
```

| Code                         | Type                    | Status | Meaning                                                                                                            |
| ---------------------------- | ----------------------- | ------ | ------------------------------------------------------------------------------------------------------------------ |
| `invalid_api_key`            | `authentication_error`  | 401    | The API key is missing, malformed or revoked.                                                                      |
| `validation_failed`          | `invalid_request_error` | 422    | A parameter is missing or invalid. Unknown parameters are rejected, not ignored. See param and errors.             |
| `content_policy_violation`   | `invalid_request_error` | 400    | The prompt was rejected. Nothing was created or billed.                                                            |
| `not_found`                  | `invalid_request_error` | 404    | No such resource, or it belongs to another account (you can't tell which).                                         |
| `idempotency_key_reused`     | `idempotency_error`     | 409    | The Idempotency-Key was already used with different parameters.                                                    |
| `rate_limit_exceeded`        | `rate_limit_error`      | 429    | Too many requests this minute. Wait for the seconds in the Retry-After header.                                     |
| `concurrency_limit_exceeded` | `rate_limit_error`      | 429    | Too many generations queued or processing. Retry-After: 5. Retry once one finishes.                                |
| `daily_limit_exceeded`       | `rate_limit_error`      | 429    | The daily generation quota (UTC) is used up.                                                                       |
| `overloaded`                 | `api_error`             | 503    | Pixelforge is temporarily overloaded. Retry-After: 2. Nothing was created; retry, ideally with an Idempotency-Key. |
| `date_range_too_long`        | `invalid_request_error` | 422    | The usage date range covers too many days.                                                                         |

## Rate limits and quotas

Limits apply per account, across all of its API keys.

| Limit                  | Value                          | When exceeded                                      |
| ---------------------- | ------------------------------ | -------------------------------------------------- |
| Requests               | 60 per minute                  | `429 rate_limit_exceeded`, with Retry-After        |
| Concurrent generations | 3 queued or processing at once | `429 concurrency_limit_exceeded`, with Retry-After |
| Generations per day    | 500 per UTC day                | `429 daily_limit_exceeded`                         |

Rejected requests create nothing and cost nothing. Respect the `Retry-After` header (seconds) before retrying, and cap your retries: back off further each time, and give up after a few attempts rather than retrying forever. Every retry counts towards the per-minute limit.

Every authenticated response includes `X-RateLimit-Limit` (requests allowed per minute) and `X-RateLimit-Remaining` (requests left this minute).

## Idempotency

Networks fail. To retry `POST /v1/generations` safely, send an `Idempotency-Key` header (any unique string up to 255 characters, such as a UUID or your own order id).

- Same key and same parameters: you get the same generation back, as it is now (so its status may have moved on since the first request), with status `202` and the header `Idempotent-Replayed: true`. No new generation is created, nothing extra is billed, and the request doesn't count against the concurrency or daily limits.
- Same key with different parameters: `409 idempotency_key_reused` (type `idempotency_error`).
- Keys are scoped to your account and never expire.

## Create a generation

POST/v1/generations

| Parameter          | Type             | Description                                                                                                                                                                       |
| ------------------ | ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `prompt`           | string, required | What to generate. At most 4,000 characters.                                                                                                                                       |
| `size`             | string           | `1024x1024` (default), `1536x1024` or `1024x1536`.                                                                                                                                |
| `quality`          | string           | `low`, `medium` (default) or `high`.                                                                                                                                              |
| `reference_images` | array of URLs    | Optional http(s) image URLs to guide the style, at most 4. Pixelforge doesn't download them, but each is billed as 765 input image tokens.                                        |
| `metadata`         | object           | Optional string keys to string values (at most 20 keys, values up to 500 characters). Returned on the generation and in every webhook, so you can match them to your own records. |

Any other parameter is rejected with `422 validation_failed`, so a typo can't silently fall back to a default.

bashCopy

```
curl https://pixelforge.promptedit.dev/v1/generations \
  -H "Authorization: Bearer $PIXELFORGE_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10" \
  -d '{
    "prompt": "A lighthouse on a cliff at dawn, oil painting",
    "size": "1536x1024",
    "quality": "high",
    "reference_images": ["https://example.com/style.png"],
    "metadata": { "order_id": "1042" }
  }'
```

Returns `202 Accepted` with the [generation object](https://pixelforge.promptedit.dev/docs#generation-object), in the `queued` status.

jsonCopy

```
{
  "id": "gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K",
  "object": "generation",
  "status": "queued",
  "prompt": "A lighthouse on a cliff at dawn, oil painting",
  "size": "1536x1024",
  "quality": "high",
  "reference_images": ["https://example.com/style.png"],
  "metadata": { "order_id": "1042" },
  "image_url": null,
  "image_url_expires_at": null,
  "usage": null,
  "rates": {
    "input_text_tokens": "5.00",
    "input_image_tokens": "10.00",
    "output_tokens": "40.00",
    "reasoning_tokens": "12.50"
  },
  "error": null,
  "created_at": "2026-11-04T14:03:11Z",
  "started_at": null,
  "completed_at": null
}
```

## Retrieve a generation

GET/v1/generations/{id}

Returns the generation object. Generations belonging to other accounts return `404 not_found`.

## List generations

GET/v1/generations

Your generations, newest first, with cursor pagination.

| Query parameter | Description                                                                         |
| --------------- | ----------------------------------------------------------------------------------- |
| `limit`         | 1 to 100, default 20.                                                               |
| `status`        | Only generations with this status: `queued`, `processing`, `succeeded` or `failed`. |
| `cursor`        | The `next_cursor` from the previous page.                                           |

bashCopy

```
curl "https://pixelforge.promptedit.dev/v1/generations?limit=2&status=succeeded" \
  -H "Authorization: Bearer $PIXELFORGE_API_KEY"

{
  "object": "list",
  "data": [ { "id": "gen_…", "object": "generation", … }, … ],
  "has_more": true,
  "next_cursor": "eyJjcmVhdGVkX2F0Ijoi…"
}
```

## The generation object

| Field                                                       | Description                                                                                                                                                                                 |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                                                        | Unique id, prefixed `gen_`.                                                                                                                                                                 |
| `object`                                                    | Always `generation`.                                                                                                                                                                        |
| `status`                                                    | `queued`, `processing`, `succeeded` or `failed`.                                                                                                                                            |
| `prompt`, `size`, `quality`, `reference_images`, `metadata` | As requested, with defaults filled in. `metadata` is always an object (empty if none was sent).                                                                                             |
| `image_url`                                                 | Signed PNG URL once succeeded, otherwise null. See [Images](https://pixelforge.promptedit.dev/docs#images).                                                                                 |
| `image_url_expires_at`                                      | When `image_url` stops working.                                                                                                                                                             |
| `usage`                                                     | Null until succeeded, then `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens` and `total_tokens`.                                                               |
| `rates`                                                     | The rates this generation is billed at, in US dollars per 1M tokens, with the same keys as `GET /v1/pricing`. Fixed when the generation is created, so later price changes don't affect it. |
| `error`                                                     | Null unless failed, then `code` and `message`.                                                                                                                                              |
| `created_at`, `started_at`, `completed_at`                  | When it was accepted, started processing and finished (succeeded or failed).                                                                                                                |

### Lifecycle

`queued` → `processing` → `succeeded` or `failed`. A generation typically waits a few seconds in the queue, then processes for 5 to 30 seconds. `succeeded` and `failed` are final.

A succeeded generationCopy

```
{
  "id": "gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K",
  "object": "generation",
  "status": "succeeded",
  "prompt": "A lighthouse on a cliff at dawn, oil painting",
  "size": "1536x1024",
  "quality": "high",
  "reference_images": ["https://example.com/style.png"],
  "metadata": { "order_id": "1042" },
  "image_url": "https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1762351409&signature=…",
  "image_url_expires_at": "2026-11-05T14:03:29Z",
  "usage": {
    "input_text_tokens": 12,
    "input_image_tokens": 765,
    "output_tokens": 6450,
    "reasoning_tokens": 3184,
    "total_tokens": 10411
  },
  "rates": {
    "input_text_tokens": "5.00",
    "input_image_tokens": "10.00",
    "output_tokens": "40.00",
    "reasoning_tokens": "12.50"
  },
  "error": null,
  "created_at": "2026-11-04T14:03:11Z",
  "started_at": "2026-11-04T14:03:13Z",
  "completed_at": "2026-11-04T14:03:29Z"
}
```

Failed generations are never billed. The error code is `generation_failed` (the model couldn't produce an image for the prompt) or `internal_error` (something went wrong on our side; retrying usually works).

A failed generationCopy

```
"error": {
  "code": "internal_error",
  "message": "Something went wrong on our side while generating. You were not charged; please retry."
}
```

The API never returns a price

Responses and webhooks report token usage only. Work out the cost yourself from `usage` and the [pricing](https://pixelforge.promptedit.dev/docs#pricing).

## Pricing and token usage

You pay per token, at a different rate for each kind of token. Only succeeded generations are billed.

| Token type         | Price per 1M tokens |
| ------------------ | ------------------- |
| Input text tokens  | $5.00               |
| Input image tokens | $10.00              |
| Output tokens      | $40.00              |
| Reasoning tokens   | $12.50              |

Each generation is billed at the rates in force when it was created. Rates can change, so read them from the pricing endpoint rather than hardcoding them. It needs no authentication.

GET/v1/pricing

bashCopy

```
curl https://pixelforge.promptedit.dev/v1/pricing

{
  "object": "pricing",
  "currency": "usd",
  "unit": "per_1m_tokens",
  "rates": {
    "input_text_tokens": "5.00",
    "input_image_tokens": "10.00",
    "output_tokens": "40.00",
    "reasoning_tokens": "12.50"
  },
  "tokens": {
    "characters_per_text_token": 4,
    "text_token_variance": 0.15,
    "per_reference_image": 765,
    "output": {
      "low": {
        "1024x1024": 280,
        "1536x1024": 420,
        "1024x1536": 420
      },
      "medium": {
        "1024x1024": 1100,
        "1536x1024": 1650,
        "1024x1536": 1650
      },
      "high": {
        "1024x1024": 4300,
        "1536x1024": 6450,
        "1024x1536": 6450
      }
    },
    "reasoning": {
      "low": { "min": 0, "max": 500 },
      "medium": { "min": 200, "max": 2000 },
      "high": { "min": 1000, "max": 8000 }
    }
  }
}
```

### How tokens are counted

- **Input text tokens:** about one token per 4 characters of prompt (Unicode characters, not bytes), so estimate `ceil(characters / 4)`. The real count is within 15% of that estimate in either direction, and is only known once the generation completes.
- **Input image tokens:** exactly 765 per reference image.
- **Output tokens:** exact, by quality and size:

| Quality  | 1024x1024 | 1536x1024 | 1024x1536 |
| -------- | --------- | --------- | --------- |
| `low`    | 280       | 420       | 420       |
| `medium` | 1,100     | 1,650     | 1,650     |
| `high`   | 4,300     | 6,450     | 6,450     |

- **Reasoning tokens:** the model thinks before drawing, and how much varies per generation. The count is only known once the generation completes, but always falls within a range set by quality:

| Quality  | Minimum | Maximum |
| -------- | ------- | ------- |
| `low`    | 0       | 500     |
| `medium` | 200     | 2,000   |
| `high`   | 1,000   | 8,000   |

### Worked example: the most a generation can cost

A `high` quality `1536x1024` image with a 400-character prompt and 2 reference images, assuming the most text and reasoning tokens possible:

| Tokens                                         | Count                     | × price per 1M | Cost          |
| ---------------------------------------------- | ------------------------- | -------------- | ------------- |
| Input text 400 / 4 = 100 estimated, up to +15% | 115                       | $5.00          | `0.000575000` |
| Input image 2 × 765                            | 1,530                     | $10.00         | `0.015300000` |
| Output high, 1536x1024 (exact)                 | 6,450                     | $40.00         | `0.258000000` |
| Reasoning maximum for high quality             | 8,000                     | $12.50         | `0.100000000` |
| Maximum total                                  | `0.373875000` ($0.373875) |

Costs are exact: one token at $1.00 per 1M is $0.000001, and amounts are never rounded. Floating-point arithmetic will drift; use integers (for example nanodollars) or decimals.

## Usage

GET/v1/usage

What Pixelforge billed for generations created with the API key you call it with, per UTC day. This is the source of truth for that key's provider costs, so reconcile your own records against it. The dashboard shows the same numbers for each key, or for all of your keys together.

- `start_date` and `end_date`: `YYYY-MM-DD` in UTC, inclusive. Default: the last 30 days, ending today. At most 92 days.
- A generation is billed on the UTC day it _completed_, not the day it was created.
- `cost_usd` is an exact, unrounded decimal string with nine decimal places.
- Days without usage are included, with zeros.

bashCopy

```
curl "https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-01&end_date=2026-11-02" \
  -H "Authorization: Bearer $PIXELFORGE_API_KEY"

{
  "object": "usage",
  "start_date": "2026-11-01",
  "end_date": "2026-11-02",
  "data": [\
    {\
      "date": "2026-11-01",\
      "generations": 3,\
      "input_text_tokens": 41,\
      "input_image_tokens": 0,\
      "output_tokens": 3300,\
      "reasoning_tokens": 2950,\
      "cost_usd": "0.169080000"\
    },\
    {\
      "date": "2026-11-02",\
      "generations": 0,\
      "input_text_tokens": 0,\
      "input_image_tokens": 0,\
      "output_tokens": 0,\
      "reasoning_tokens": 0,\
      "cost_usd": "0.000000000"\
    }\
  ],
  "total": {
    "generations": 3,
    "input_text_tokens": 41,
    "input_image_tokens": 0,
    "output_tokens": 3300,
    "reasoning_tokens": 2950,
    "cost_usd": "0.169080000"
  }
}
```

## Webhooks

Each API key has its own webhook URL and signing secret. Whenever a generation changes status, Pixelforge sends an HTTP `POST` to the webhook URL of the key that created it, signed with that key's secret. Other keys never see the event. Set each key's URL and copy its signing secret in the dashboard.

| Event                   | Sent when                                            |
| ----------------------- | ---------------------------------------------------- |
| `generation.processing` | A generation leaves the queue.                       |
| `generation.succeeded`  | A generation finished, with `usage` and `image_url`. |
| `generation.failed`     | A generation failed, with `error`.                   |
| `ping`                  | You pressed "Send test event" in the dashboard.      |

The body is an event whose `data.object` is the full [generation object](https://pixelforge.promptedit.dev/docs#generation-object) at the moment the event happened.

jsonCopy

```
{
  "id": "evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A",
  "object": "event",
  "type": "generation.succeeded",
  "created_at": "2026-11-04T14:03:29Z",
  "data": {
    "object": {
      "id": "gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K",
      "object": "generation",
      "status": "succeeded",
      …the full generation object…
    }
  }
}
```

HeadersCopy

```
POST /webhooks/pixelforge HTTP/1.1
Content-Type: application/json
User-Agent: Pixelforge-Webhooks/1.0
Pixelforge-Event-Id: evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A
Pixelforge-Event-Type: generation.succeeded
Pixelforge-Delivery-Attempt: 1
Pixelforge-Signature: t=1762265009,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd
```

`Pixelforge-Delivery-Attempt` counts every attempt to deliver the event, including automatic retries and resends from the dashboard.

### Verifying signatures

Anyone can POST to your URL, so check every request. The `Pixelforge-Signature` header is `t=<unix timestamp>,v1=<signature>`, where the signature is the hex HMAC-SHA256 of `<timestamp>.<raw request body>`, keyed with the signing secret of the API key that created the generation (`whsec_…`). Compare in constant time, and reject old timestamps to stop replays. Each attempt is signed with a fresh timestamp.

Laravel exampleCopy

```
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController
{
    public function __invoke(Request $request): Response
    {
        // Verify against the raw body, before any JSON decoding.
        $payload = $request->getContent();
        $signature = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $signature[$key] = $value;
        }

        $timestamp = (int) ($signature['t'] ?? 0);
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", config('services.pixelforge.webhook_secret'));

        if (! hash_equals($expected, $signature['v1'] ?? '') || abs(time() - $timestamp) > 300) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($payload, true);

        // Deduplicate by $event['id'] before acting on the event.

        return response()->noContent();
    }
}
```

Register the route outside the `web` middleware group (or exclude it from CSRF protection), since Pixelforge has no CSRF token.

### Responding and retries

Any `2xx` response within 10 seconds counts as delivered. Anything else (another status, a timeout, a connection error) is retried after 10s, 30s, 2m, 10m, 15m: up to 6 attempts in total. Redirects are not followed. Respond quickly and do slow work in a queued job.

Delivery is at least once, in any order

- The same event can arrive **more than once**. Deduplicate by the event `id`.
- Events can arrive **out of order**. A retried `generation.processing` can arrive after `generation.succeeded`. Go by the generation's status and never move a generation back from `succeeded` or `failed`.
- A webhook can arrive **before** your `POST /v1/generations` request has returned.
- Delivery isn't guaranteed. If every attempt fails, you can still poll `GET /v1/generations/{id}`.

### Local development

Webhook URLs must be publicly reachable `http` or `https` URLs. Addresses on private or loopback networks (`localhost`, `127.0.0.1`, `10.x`, ...) are rejected. While developing, expose your app with a tunnel such as Expose, ngrok or Cloudflare Tunnel.

The dashboard shows every delivery attempt with the response your endpoint returned, and lets you resend any event.

## Images

A succeeded generation's `image_url` points to a PNG at the requested size. The URL is signed and stops working 24 hours after the generation completed (`image_url_expires_at`). Download the file and store it yourself if you need it for longer. You can fetch a fresh URL from `GET /v1/generations/{id}`, but it expires at the same time.

## Testing

Put any of these keywords anywhere in a prompt to trigger a behaviour on purpose. They are case-insensitive and still count toward the prompt's text tokens.

| Keyword        | Behaviour                                                                                 |
| -------------- | ----------------------------------------------------------------------------------------- |
| `[blocked]`    | Rejected at submission with 400 content\_policy\_violation. Nothing is created or billed. |
| `[overloaded]` | Rejected at submission with 503 overloaded. Nothing is created or billed.                 |
| `[fail]`       | Accepted, then fails while processing (generation.failed). Not billed.                    |
| `[succeed]`    | Accepted and never fails at random.                                                       |
| `[slow]`       | Takes 60 to 90 seconds to process instead of 5 to 30.                                     |
| `[duplicate]`  | Every webhook event for the generation is delivered twice.                                |

Without keywords, Pixelforge still misbehaves now and then, like a real provider: a small share of generations fail at random, a small share of create requests are rejected with `503 overloaded`, and some webhook events are deliberately delivered twice.
