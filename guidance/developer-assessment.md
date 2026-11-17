# Senior Product Engineer: take-home assessment

This assessment requires to integrate an AI provider, charge users accurately for what they generate, and build a UI people enjoy using.

## The provider: Pixelforge

[Pixelforge](https://pixelforge.promptedit.dev/) is a fictional AI image-generation API built for this assessment. It behaves like real providers:

- Generations are **asynchronous**. You submit a request, then learn the result from a **webhook** or by polling.
- It's **priced per token**. Some token counts are only known once a generation finishes, and the API never tells you a price. You work it out from the token usage and the published rates.
- It **fails sometimes**: rate limits, concurrency limits, overloads, generations that fail after being accepted, and webhooks delivered more than once or out of order.

Everything is documented in the [API docs](pixelforge-api-docs.md). Read them carefully. Most of what will be assessed is in there.

## What to build

Extend the provided starter app that uses **PHP 8.4, Laravel 13, Vue 3 and Inertia** to create an app where users can generate images. In the backend, requests should be passed to Pixelforge, then show the generated images in the user's UI once they finish. Users sign in, and the app records what each generation cost them. Credits are simply US dollars here.

1. **Generating.** A page where users write a prompt and choose a size, quality and optional reference image URLs. Show them what it will cost **before** they submit.
2. **Pricing.** Users pay Pixelforge's cost **plus a 25% markup**. Part of Pixelforge's cost is only known after a generation completes, so the price you show up front is an estimate. Once a generation finishes, record what it actually cost the user. Generations that fail cost nothing. Pixelforge's costs come in fractions of a cent; how you handle that is up to you.
3. **History.** Users see their generations with live status (queued, processing, succeeded or failed), the estimated and the actual cost, and the image. It's fine if images stop loading after Pixelforge's URLs expire.
4. **Webhooks.** Receive Pixelforge's webhooks and verify their signatures. The app must reach the right state even when events arrive twice or out of order, and an event delivered twice must never be recorded twice.
5. **Deploy it** somewhere public, on any host you like. Laravel Cloud is probably the easiest.

### Stretch goals (bonus points)

Only once everything above works. Either or both earn bonus points:

1. **Admin report.** A page for site owners, showing credit spend (what users were charged), provider costs (what Pixelforge charged) and profit, for each day and each user. The provider costs should match what Pixelforge reports in `GET /v1/usage`, this will be verified against.
2. **Balances.** Track each user's credit balance, add a "Buy credits" button that adds credits to their account (a fake purchase, no real payments), and block generating images when the user can't afford it.

Some details are deliberately left open. When something is unclear, provide reasoned options and seek guidance.

## Coding
- Prefer modern Laravel and PHP code.
- Use DDD where applicable.
- Laravel Boost has been setup, use it.
- Use pest tests
- Do not mock http calls as a local development API key will be used
- The pixel forge api key is provided in the .env as PIXELFORGE_API_KEY
- Use commits per feature and do not use destructive git commands

## Assessment

- **Put correctness ahead of polish.** a small app that records every cost exactly right beats a bigger one that doesn't.
- **Correct money.** Every generation is recorded at exactly the right cost, never twice, and failed generations are free.
- **Robustness.** The app copes with what Pixelforge does: limits, overloads, failures, and duplicate or out-of-order webhooks.
- **Product sense and UX.** It's clear what things cost, what's happening, and what went wrong.
- **Code quality.** Code that's easy to read and change.

## Tips

- The docs list prompt keywords such as `[fail]`, `[slow]` and `[duplicate]` that force specific behaviour, so you can test the error handling on purpose.