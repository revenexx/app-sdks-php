# revenexx/app-sdk (PHP)

The PHP package's own version line. It is released by tagging `php-vX.Y.Z` in
revenexx/app-sdks; the split workflow publishes it as `vX.Y.Z` in
revenexx/app-sdks-php, which Packagist reads. The JavaScript package
(`@revenexx/app-sdk`) is versioned separately. The two are held to one contract
by the parity fixture, not by a shared version number.

## 1.1.0

- **Readable refusals:** every refusal body also carries `message` (= `error`) and
  `type` (= `code`), the names the platform client reads. Its exception now carries
  the App's reason and code instead of an empty message (RAD-98).
- **`Refusal::of($e)`** reads a refusal into status, code, message, details and the
  answering App. It handles this App's own `HttpError` and another App's refusal
  caught from the platform client alike. For a crash it returns null.
- **An uncaught refusal from another App is logged as what the far side said.**
  The caller still gets `500 internal_error`.

## 1.0.0

The first release. The surface is bound under semver from here, under the same
policy as the JavaScript package: a narrowing or a removed name is a major.

- **Data client:** `Client` with `list`, `page`, `get`, `create`, `update`,
  `updateIf` and `remove`. Operations the manifest does not grant are refused.
- **Adapters:** `mock`, `remote` and `runtime`. `runtime` is configured from the
  function's own request (ADR-0057).
- **Errors:** `HttpError` with stable codes. PostgREST refusals are mapped by
  `PostgrestError`, and no table or constraint name reaches a caller.
- **Router:** `Router\App` and `Router\RouteContext`. Answers go out as JSON through
  `send()`, so the runtime's `JSON_FORCE_OBJECT` never turns a list into an object.
- **One-line CRUD:** `Router\Crud::mount`. A filter that cannot be applied is
  refused, never ignored.
- **Acting person:** `Router\Actor`.
- **Settings:** `Settings`, which is market-aware, cached and fails open.
- **Events:** the App's own events — `events()->emit()`, recorded by the mock.

Not offered: tracing.
