# Changelog

All notable changes to this package are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.0.1] - 2026-10-12

First release. A PHP SDK for Salsify's **ProductXM** API, usable from Laravel,
Symfony or plain PHP.

### Added

- **Records** — `get`, `create`, `update`, `delete`, plus the legacy
  `products` path. `Record` is schemaless and keeps every property exactly as
  it arrived, with typed accessors for the `salsify:` system fields
  (`id`, `systemId`, `parentId`, `version`, `createdAt`, `updatedAt`,
  `digitalAssets`) and `localized()` / `locales()` for localized values.
- **Exports** — `start`, `status`, `stream`. `ExportConfiguration` enforces
  Salsify's format/entity-type pairing before a request is made, and
  `JsonLines` reads an export of any size in constant memory, gzip included,
  through `compress.zlib://`.
- **Imports** — start a configured import and ask how it went.
- **Webhook signature verification** — the five-part signed string, with the
  endpoint URL supplied by configuration rather than read from the request.
  Certificates are fetched over PSR-18 and cached for the life of the process.
- **Rate limit visibility** — the three `x-ratelimit-*` headers are read from
  every response and surfaced through `Salsify::rateLimit()`, with
  `isRunningOut()` and `isExhausted()`.
- **Typed exceptions** — `Unauthorized`, `NotFound`, `RateLimited` (carrying
  `resetsAt()`), `TransportFailed`, `InvalidArgument`, all `ApiError` or
  `SalsifyException`, each keeping the status and the decoded body.
- **Laravel bridge** — auto-discovered service provider, publishable config,
  and `SignatureVerifier` bound separately so a webhook endpoint can resolve
  it without the rest of the SDK. `WebhookRequest::from()` reads the raw body.
- **Symfony bridge** — `AbstractBundle` with a validated configuration tree;
  `SignatureVerifier` is registered only when `webhook_url` is set.
- **PSR-18 / PSR-17 throughout**, with optional discovery via
  `php-http/discovery` and no hard dependency on any HTTP client.
- **141 tests**, PHPStan at level `max`, Pint, and CI across PHP 8.2 → 8.5
  plus a Symfony 6.4 job built on a vendor tree with no Laravel in it.

### Security

- The webhook **certificate URL is validated before it is fetched** — https
  scheme, and the host exactly `webhooks-auth.salsify.com`. Fetching whatever
  a request header names is server-side request forgery; the check runs in the
  verifier *and* again inside the fetcher, next to the network call. Nine
  untrusted URLs are tested, each asserting the fetcher was never called.
- **Timestamps from the future are refused.** Salsify's documented tolerance
  is one-sided, which would leave a captured request replayable indefinitely
  if its timestamp were set far enough ahead.
- **Signatures are verified against the raw request body**, never a
  re-encoded one, and the token is sent only in the `Authorization` header —
  never as the `?access_token=` query parameter Salsify also accepts.
- `Credentials` refuses a token pasted with a `Bearer ` prefix, and masks the
  token in `var_dump` output.

### Notes

- **The version is deliberately `0.0.1`.** The two sibling packages for
  Monetico and Worldline Sips opened at `0.1.0`, but both could be measured
  against a real platform. This one cannot be, yet, and the version number
  says so rather than leaving the README to say it alone.
- **Nothing in this release has been verified against the live Salsify API.**
  There is no public sandbox and API access is a licence option, so every
  request shape comes from Salsify's published reference and OpenAPI
  definitions rather than from an observed response. The README states, per
  feature, what is proved and what is only documented.
- `ExportClient::status()` uses the conventional `export_runs/{id}` path:
  Salsify documents the start call in full and polling only in prose. The
  docblock says so rather than hiding it.
- Deliberately out of scope: SupplierXM, the GraphQL API, workflows, Enhanced
  Content, SSO and user provisioning, OAuth 2.0, import mount points and file
  uploads, and any paginated `records()->all()`.

[Unreleased]: https://github.com/xPiston/salsify/compare/v0.0.1...HEAD
[0.0.1]: https://github.com/xPiston/salsify/releases/tag/v0.0.1
