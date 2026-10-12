<div align="center">

# 🗂️ Salsify for PHP

**The PIM, as a few small objects.**

Records, exports you can stream, imports, and webhook signatures that are
actually verified — for Laravel, Symfony, or no framework at all.

[![PHP](https://img.shields.io/badge/PHP-8.2%20%E2%86%92%208.5-777BB4?logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![Tests](https://img.shields.io/badge/tests-141-22C55E)](#-tests)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-2563EB)](#-tests)
[![License](https://img.shields.io/badge/license-MIT-blue)](#license)

</div>

---

[Salsify](https://developers.salsify.com) is a product information management
platform. Its **ProductXM** API is the one this package speaks: an
organization, a token, records that are products, exports that are files, and
webhooks that are signed.

What it gives you is a typed way to do the four things an integration really
does — read a product, mirror a catalogue, push a feed, and trust an incoming
webhook — with the platform's two awkward facts made impossible to get wrong:
**the rate limit belongs to the organization, not to you**, and **the webhook
signature covers a URL that is not in the request**.

> ### ⚠️ Read this before you trust it
>
> **Nothing here has been checked against the live Salsify API.** Salsify has
> no public sandbox, and API access is a licence option rather than something
> you can sign up for — so unlike a package built against a published test
> platform, there was no server to measure. Every request this library builds
> comes from Salsify's own documentation and OpenAPI definitions, not from a
> response anybody observed.
>
> What *is* proved, offline and end to end, is the **webhook signature
> verification** — see [what's proved and what's read](#-whats-proved-and-whats-read).
> If you have API access, the fastest useful thing you can do with this package
> is run one call and [tell me what came back](#-if-you-have-a-token).

## ✨ What's inside

- 📇 **Records** — get, create, update, delete, with `salsify:` system fields
  read as PHP types and everything else kept exactly as it arrived
- 📤 **Exports that stream** — start, poll, then read a JSON Lines file of any
  size in constant memory. A 50 000-record file is read in under a megabyte
  — [measured by a test](#-tests), not asserted in prose
- 📥 **Imports** — start a configured import and ask how it went
- 🔐 **Webhook signatures, verified properly** — the five-part signed string,
  a certificate URL that is **vetted before it is fetched**, and timestamps
  refused from the future as well as from the past
- 📊 **The rate limit, surfaced after every call** — `isRunningOut()` fires
  before the 429 instead of after it
- 🧊 **No retries, ever** — and [a paragraph on why](#-three-things-this-sdk-refuses-to-do)
- 🌍 **Localized values** — `localized('Description', 'fr-FR')` instead of
  digging through a nested array
- 🧩 **Laravel and Symfony bridges**, a hundred lines each, both optional
- 🧪 **141 tests**, PHPStan at **max**, zero network

## 🚀 Quick start

```sh
composer require xpiston/salsify
```

You also need any PSR-18 client. If you have none, this is the shortest way:

```sh
composer require symfony/http-client nyholm/psr7 php-http/discovery
```

```php
use Piston\Salsify\Salsify;

$salsify = Salsify::with('your-api-token', 's-1234-5678-9012-3456');

$record = $salsify->records()->get('ABC-123');

$record->property('Brand');                       // 'Acme'
$record->localized('Description', 'fr-FR');       // 'Une description'
$record->version();                               // 7
$record->createdAt()?->format('Y-m-d');           // '2021-06-21'

echo $salsify->rateLimit();   // "9998 of 10000 requests left, resetting in 2410s"
```

The organization id is in the Salsify app URL, right after `/orgs/`. It is not
optional and not decoration: see [the rate limit](#-the-rate-limit-is-the-organizations).

### Changing one product

```php
// Only what moved. Salsify modifies only the properties you mention, so
// reading a record and writing it back whole would overwrite whatever
// somebody else changed in between.
$salsify->records()->update('ABC-123', ['Color' => 'Red', 'In Stock' => true]);
```

### Creating one

```php
use Piston\Salsify\Entity\Record;

$salsify->records()->create(
    Record::of('SKU', 'ABC-124', ['Brand' => 'Acme', 'Color' => 'Blue'])
);
```

The id is a *property* — whichever one holds the ID role in your organization —
so it goes in the record rather than beside it.

## 📦 Exporting a whole catalogue

This is the part worth reading, because it is the part an integration gets
wrong. Three calls, not one:

```php
use Piston\Salsify\Exports\ExportConfiguration;

// 1. Ask. Returns immediately with an id.
$run = $salsify->exports()->start(ExportConfiguration::everything());

// 2. Come back later. Minutes to hours - from a scheduled job, not a web request.
$run = $salsify->exports()->status($run);

if (! $run->isReady()) {
    return;   // $run->progress() is 0 to 100
}

// 3. Read it, one line at a time, whatever its size.
foreach ($salsify->exports()->stream($run, compressed: true) as $line) {
    if ($line->isRecord()) {
        $record = $line->toRecord();
        // ...
    }
}
```

There is deliberately **no** `exportAndWait()`. A full export takes minutes to
hours; a method that polled until it finished would hold a web request or a
queue worker open, and would hide the one decision that matters — how often to
ask — which is a question about your organization's ten thousand requests an
hour and not one a library can answer for you.

### What a JSON Lines export actually contains

It is a data model told in order, not a list of products:

```
header             one line: version, organization, when
property           your attributes, with their data types and roles
enumerated_value   the allowed values of the enumerated ones
digital_asset      images and documents, with their URLs
record             the products, referring to all of the above
```

`Line` gives you `isHeader()`, `isProperty()`, `isEnumeratedValue()`,
`isDigitalAsset()`, `isRecord()` — and **keeps anything it does not
recognise**. Salsify adds to this format, and a reader that silently dropped
unknown entity types would lose data from a newer organization than itself,
which for a mirror is the worst failure there is.

### It really does stream

```php
use Piston\Salsify\Exports\JsonLines;

foreach (JsonLines::records(JsonLines::open('/tmp/export.jsonl.gz', compressed: true)) as $record) {
    // One record in memory at a time.
}
```

`compressed: true` reads a gzip file through `compress.zlib://` without
shelling out and without unpacking it to disk. One of the tests builds a
50 000-record file and asserts that streaming it costs under a megabyte of
memory *and* that `file_get_contents` on the same file costs at least five
times more — so the claim fails loudly if the generator is ever broken by a
refactor.

## 🔐 Webhooks

Salsify signs each webhook with RSA-SHA256 over five values joined by periods:

```
timestamp . request_id . organization_id . endpoint_url . body
```

**Four of those five are in the request. The fifth is not.** The endpoint URL
is the one Salsify was configured to call, and a verifier has to supply it from
its own configuration. Rebuilding it from the request — from `Host`, from
`REQUEST_URI`, from a proxy header — means checking a signature against a value
the sender controls, which is not a check at all. So this library makes the URL
a required constructor argument; there is no way to call it without one.

```php
use Piston\Salsify\Webhooks\InvalidSignature;
use Piston\Salsify\Webhooks\SignedRequest;

$verifier = $salsify->webhooks('https://shop.example.com/webhooks/salsify');

$webhook = SignedRequest::fromHeaders(getallheaders(), file_get_contents('php://input'));

try {
    $verifier->verify($webhook);
} catch (InvalidSignature $e) {
    http_response_code(403);
    error_log($e->getMessage());   // says which of six things was wrong
    exit;
}
```

Headers are matched on a normalised form, so PHP's `HTTP_X_SALSIFY_TIMESTAMP`,
PSR-7's `x-salsify-timestamp` and Salsify's own `X-Salsify-Timestamp` all work
without the caller having to know which stack it is on.

### Two things this does that the published example does not

**It validates the certificate URL before fetching it.** The URL arrives in a
header. Fetching whatever a header says is server-side request forgery with
extra steps: point it at `169.254.169.254` and the application obligingly
requests the cloud metadata service. So the scheme must be `https` and the host
must be *exactly* `webhooks-auth.salsify.com` — checked before anything is
downloaded, and checked again inside the fetcher, because that class is public
and the day somebody calls it directly the check has to be next to the network
call rather than in the caller who skipped it.

A test asserts each of nine untrusted URLs is refused **and that the fetcher
was never called** — a subdomain, a lookalike host, `http`, a userinfo trick,
a redirect target, the metadata address. Refusing them is easy; refusing them
*before the request* is the thing worth testing.

**It rejects timestamps from the future.** Salsify's tolerance is documented
one-sided — ignore requests that are too old — which leaves a captured request
replayable forever if its timestamp is set far enough ahead. A few seconds of
clock skew is a clock; an hour is a forgery with a long life.

### The raw body, not the parsed one

```php
// Laravel
use Piston\Salsify\Bridge\Laravel\WebhookRequest;

$webhook = WebhookRequest::from($request);   // uses $request->getContent()
```

```php
// Symfony
use Piston\Salsify\Bridge\Symfony\WebhookRequest;

$webhook = WebhookRequest::from($request);
```

Both read the raw body deliberately. A JSON document that has been through
`json_decode` and back is a different string the moment a key order, a float or
an escape changes — and it will verify perfectly in your tests and fail on the
first real payload that happens to differ. There is a test that signs
`{"b":1, "a":2, "price": 1.10}` and proves the re-encoded version does not
verify.

## 📈 The rate limit is the organization's

Salsify allows **10 000 requests per hour per organization** — not per user,
not per token. Three consequences this library is built around:

1. **A 429 is as likely to be somebody else's fault as yours.** Another
   integration in the same organization can spend the budget.
2. **The window resets at the top of the hour**, not sixty minutes after your
   first request. A job that runs dry at 16:45 resumes at 17:00 with ten
   thousand.
3. **A loop over a catalogue cannot work.** Fifty thousand products through the
   record endpoints is five hours of the whole organization's budget. Use an
   export to read and an import to write — Salsify says the same thing, and the
   arithmetic says it louder.

```php
$limit = $salsify->rateLimit();

$limit->remaining;          // 9 412
$limit->isRunningOut();     // true below a twentieth - 500 of 10 000
$limit->isExhausted();
$limit->resetsIn;           // seconds until the top of the hour
```

And when it does happen:

```php
use Piston\Salsify\Exception\RateLimited;

try {
    $salsify->records()->get('ABC-123');
} catch (RateLimited $e) {
    $retryAt = $e->resetsAt();   // a DateTimeImmutable, from the response
}
```

## 🚫 Three things this SDK refuses to do

**It never retries.** Not a 429, not a 500. A retry loop hidden inside an SDK
is multiplied by every job your application starts, which is how one bad
afternoon becomes two. The exception carries the seconds until the reset; the
decision is yours, where you can see it.

**It never puts the token in the URL.** Salsify supports `?access_token=` and
its own documentation explains why not to — URLs are logged by proxies, by web
servers, by error trackers. The header costs nothing. `Credentials` also
refuses a token pasted *with* the `Bearer ` prefix, because that produces
`Bearer Bearer …` and a 401 that looks like a bad token.

**It never pretends an error is a result.** A 4xx is an exception carrying the
status and the decoded body, because Salsify's error bodies are useful — a 400
for an ambiguous organization lists the organizations you belong to — and the
most common thing an SDK does with them is throw them away.

```php
use Piston\Salsify\Exception\SalsifyException;   // the interface, if you want one catch
use Piston\Salsify\Exception\ApiError;           // status, body, messages()
use Piston\Salsify\Exception\Unauthorized;       // 401, 403
use Piston\Salsify\Exception\NotFound;           // 404
use Piston\Salsify\Exception\RateLimited;        // 429, with resetsAt()
use Piston\Salsify\Exception\TransportFailed;    // the network, or a non-JSON body
use Piston\Salsify\Exception\InvalidArgument;    // your mistake, before any request
```

The `Unauthorized` message names the licence, because "your organization does
not have API access" is the answer often enough to be worth saying before
somebody spends an afternoon re-reading the token.

## 🧰 Laravel

The provider is auto-discovered. Two variables:

```dotenv
SALSIFY_TOKEN=your-api-token
SALSIFY_ORGANIZATION=s-1234-5678-9012-3456
SALSIFY_WEBHOOK_URL=https://shop.example.com/webhooks/salsify
```

```php
use Piston\Salsify\Salsify;

final class ProductController
{
    public function show(Salsify $salsify, string $sku)
    {
        return $salsify->records()->get($sku)->toArray();
    }
}
```

The signature verifier is a **separate binding** on purpose, so a webhook
endpoint can resolve it alone:

```php
use Piston\Salsify\Bridge\Laravel\WebhookRequest;
use Piston\Salsify\Webhooks\InvalidSignature;
use Piston\Salsify\Webhooks\SignatureVerifier;

Route::post('/webhooks/salsify', function (Request $request, SignatureVerifier $verifier) {
    try {
        $verifier->verify(WebhookRequest::from($request));
    } catch (InvalidSignature) {
        abort(403);
    }

    ProcessSalsifyWebhook::dispatch($request->json()->all());

    return response()->noContent();
});
```

`php artisan vendor:publish --tag=salsify-config` for the rest.

## 🧰 Symfony

```php
// config/bundles.php
return [
    Piston\Salsify\Bridge\Symfony\SalsifyBundle::class => ['all' => true],
];
```

```yaml
# config/packages/salsify.yaml
salsify:
    token: '%env(SALSIFY_TOKEN)%'
    organization: '%env(SALSIFY_ORGANIZATION)%'
    webhook_url: '%env(SALSIFY_WEBHOOK_URL)%'
    # webhook_tolerance: 300
```

`Salsify` and, when `webhook_url` is set, `SignatureVerifier` are autowired.
The bundle picks up your PSR-18 client and PSR-17 factories from the container
if they are there, and discovers them otherwise.

## ✅ What's proved and what's read

The honest version, feature by feature. "Documented" means it comes from
Salsify's published reference and has a test against the examples printed
there; "proved" means a test demonstrates the behaviour end to end on your
machine; "unverified" means nobody has seen the real server answer it.

| | Status |
| :-- | :-- |
| **Webhook signature verification** | ✅ **Proved.** The tests generate an RSA key and a self-signed certificate, sign exactly as Salsify does, and then forge: the body altered, the body rewritten with the same data in another order, the request id, the organization id and the timestamp each tampered with, a verifier configured with the wrong endpoint URL, a different certificate, a certificate that is not one, a signature that is not base64, a replay outside the window, both tolerance edges, a timestamp from the future, and nine untrusted certificate URLs. The algorithm does not care whose key it is, so this is the real thing. |
| **JSON Lines streaming** | ✅ **Proved.** Including the memory ceiling, with `file_get_contents` as the control. |
| **Rate limit handling** | ✅ **Proved** against responses carrying Salsify's documented headers. |
| **Record field semantics** | 📄 **Documented.** The `salsify:` system fields, localized values and digital asset shapes are the ones in the reference, with its own examples as fixtures. |
| **Request shapes** (paths, methods, bodies, the two base prefixes) | 📄 **Documented.** Every request is asserted in full against a fake PSR-18 transport — method, URL, headers, body. They match the published definitions. Whether the server agrees is **unverified**. |
| **Export start** | 📄 **Documented** — there is an OpenAPI definition for it. |
| **Export status endpoint** | ⚠️ **Unverified and flagged in the code.** Salsify documents the start call in full and describes polling only in prose. `ExportClient::status()` uses the conventional REST path, `export_runs/{id}`, and says so in its docblock rather than hiding it. |
| **Import start and status** | ⚠️ **Unverified.** The paths follow the documented pattern. |
| **Everything else about imports** | 🚫 **Absent.** See below. |

Nothing in this table is guesswork dressed up as a measurement, and nothing in
the code pretends to a certainty it does not have.

## 🙏 If you have a token

One call is enough to turn most of the ⚠️ rows above into ✅ or into a bug
report, and either is worth having:

```php
$run = $salsify->exports()->start(
    \Piston\Salsify\Exports\ExportConfiguration::everything()
);

var_dump($run->id(), $run->status(), $run->body);

// The row I would most like to hear about:
var_dump($salsify->exports()->status($run)->body);
```

[Open an issue](https://github.com/xPiston/salsify/issues) with what came back —
redact the ids — and the table above gets more honest.

## 🧱 How it's built

```
src/
├── Salsify.php              four doors: records, exports, imports, webhooks
├── Configuration/
│   ├── Credentials.php      token + organization, together, token masked in dumps
│   ├── Organization.php     the s-… id
│   └── Endpoint.php         the one place that knows there are two base paths
├── Http/
│   ├── Client.php           auth, JSON, status codes, rate limit. No retries.
│   ├── Request.php          a call before it is PSR-7
│   ├── Reply.php            a response after it is not
│   └── RateLimit.php        the three headers
├── Entity/
│   ├── Record.php           schemaless, with typed salsify: accessors
│   ├── RecordId.php         refuses ids Salsify reserves
│   └── Timestamp.php        parses Salsify's formats and no others
├── Records/RecordClient.php
├── Exports/
│   ├── ExportConfiguration.php   enforces the format/entity-type pairing
│   ├── ExportClient.php          start, status, stream
│   ├── ExportRun.php             id, progress, and a url that is "" until ready
│   ├── JsonLines.php             generators
│   └── Line.php                  the five entity types, and the unknown ones
├── Imports/
├── Webhooks/
│   ├── SignatureVerifier.php     the algorithm, plus the two hardenings
│   ├── SignedRequest.php         four headers and the raw body
│   ├── HttpCertificateFetcher.php
│   └── CachedCertificates.php    once per process, not once per webhook
└── Bridge/
    ├── Laravel/
    └── Symfony/
```

### Decisions visible in the code

- **`Endpoint` exists because Salsify has two base paths.** Records are under
  `/api/v1/orgs/{org}/`, export runs under `/api/orgs/{org}/` with no version
  segment at all — that is what their own OpenAPI definition declares. An SDK
  that assumed one prefix would work perfectly until the first export, so the
  choice is made by the caller that knows and lives in exactly one class.
- **`Record` is schemaless and keeps everything.** Your properties are your
  organization's, so there is nothing to map them to. The `salsify:` fields get
  typed accessors; everything else comes back exactly as it arrived, and
  `with()` returns a new record rather than mutating one.
- **`Timestamp` parses only Salsify's documented formats.** `new
  DateTimeImmutable($value)` would happily accept `'yesterday'` from a field
  that was supposed to hold an ISO 8601 string, and succeed — which is worse
  than failing. Anything else yields `null`.
- **`ExportRun::url()` returns `null` for an empty string.** Salsify sends
  `"url": ""` while a run is in progress. Code that checks `isset($body['url'])`
  finds it set, and downloads nothing. `isReady()` is the question to ask.
- **`RecordId` refuses ids that start with `=`, `_` or `salsify`** — the
  prefixes the platform reserves — before a request is made rather than after a
  422.
- **`Credentials` holds the token and the organization together**, because the
  rate limit is per organization: the same token has a separate budget in each
  one it belongs to, and splitting them invites a client that is right about
  one and wrong about the other.
- **No retries, no token in URLs, no silent error swallowing.** Each has a
  paragraph [above](#-three-things-this-sdk-refuses-to-do), and each is a test.

## ✅ Tests

```sh
composer install
composer test        # 141 tests, no network
composer analyse     # PHPStan, level max
composer test:lint   # Pint
```

Under three seconds, no credentials, no network. Four suites:

**`unit`** — each piece held still. The webhook verifier gets the most
attention because it is the piece a mistake in is a security hole rather than a
bug.

**`integration`** — the joins, which is where the mistakes that matter live: a
full export from start call to records, a record created-read-updated-deleted
with every request asserted in full, a webhook downloading its certificate over
PSR-18 and the certificate being fetched **once** for two deliveries.

**`laravel`** — the bridge inside a real Laravel application, booted by
Testbench.

**`symfony`** — the bundle compiling a **real** Symfony container. That is one
suite per bridge rather than one for both, because PHPUnit loads a suite's
files before it filters anything, and CI builds a vendor tree with Symfony 6.4
and no Laravel to check the LTS claim — a tree in which the Laravel test could
not even be parsed.

Two tests exist because of specific mistakes rather than specific features: the
one that proves `'yesterday'` is not a timestamp, and the one that proves the
untrusted certificate URL was never *fetched* rather than merely rejected.

## ⚠️ Not included

Deliberately, and not for lack of time:

- **SupplierXM** (the supplier-facing product) — a different API with a
  different model. This package is ProductXM only.
- **The GraphQL API** (accounts, configuration manifests) — a different
  protocol that deserves a different package, not a method on this one.
- **Workflows**, **Enhanced Content**, **SSO/SAML**, **user provisioning** —
  platform features, not data access.
- **OAuth 2.0.** Salsify documents it, but the token flow is what an
  integration uses and what an organization can actually obtain.
- **Mount points and file uploads for imports.** The multi-step flow — create a
  mount point, upload a file to it, configure an import, run it — is documented
  far more thinly than the export side, and I will not invent the parts I
  cannot read. `ImportClient` does the unambiguous half: run an import that
  exists, and ask how it went.
- **A paginated `records()->all()`.** There is no honest way to offer one that
  does not invite the loop the rate limit forbids. Export instead.
- **Retries and backoff.** [Above](#-three-things-this-sdk-refuses-to-do).

## 📚 The documentation this was built from

- [API Overview](https://developers.salsify.com/docs/overview) ·
  [Organization ID](https://developers.salsify.com/docs/organization-id) ·
  [Rate Limiting](https://developers.salsify.com/docs/rate-limiting-1)
- [Token Authentication](https://developers.salsify.com/docs/token-based-authentication) ·
  [via header or parameter](https://developers.salsify.com/docs/token-authentication-via-header-parameter)
- [Records Overview](https://developers.salsify.com/docs/records-overview) ·
  [Products Overview](https://developers.salsify.com/docs/product-object)
- [Export Overview](https://developers.salsify.com/docs/export-overview) ·
  [Start Export Run](https://developers.salsify.com/reference/start-export-run) ·
  [JSON Export File Format](https://developers.salsify.com/docs/response-guidelines)
- [Imports Overview](https://developers.salsify.com/docs/imports-overview) ·
  [JSON Lines Import Format](https://developers.salsify.com/docs/json-lines-import-format)
- [Webhook Signing](https://developers.salsify.com/docs/webhook-signing) ·
  [Signature Verification Algorithm](https://developers.salsify.com/docs/webhook-signature-verification-algorithm) ·
  [Product Change Webhooks](https://developers.salsify.com/docs/webhooks-prod-change)

Where the documentation is thin, the code says so in the docblock of the method
that had to guess. There are exactly two such places, and both are in the
[table above](#-whats-proved-and-whats-read).

## License

MIT. See [LICENSE](LICENSE).
