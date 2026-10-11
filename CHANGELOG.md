# Changelog

All notable changes to `revenexx/app-sdk` are listed here. The package follows
[semantic versioning](https://semver.org).

## 1.1.0

- **Refusals are readable through the revenexx platform client.** Every refusal body
  now also carries `message` (the same as `error`) and `type` (the same as `code`).
  These are the names the platform client reads, so a caught refusal carries the
  App's reason and code instead of an empty message.
- **`Refusal::of($e)`** reads a refusal into `status`, `code`, `message`, `details`
  and the App that answered. It works for your own `HttpError` and for another App's
  refusal caught from the platform client, and answers `null` for a crash.
- **An uncaught refusal from another App** is logged with what the other App said.
  The caller still gets `500 internal_error`.

## 1.0.0

The first release. From here, the public classes and methods, the answers of the
routes the SDK mounts, and the status and `code` of every refusal are bound by
semantic versioning.

- **Data client:** `Client` with `list`, `page`, `get`, `create`, `update`,
  `updateIf` and `remove`. An operation the manifest does not grant is refused with
  `403 not_permitted`.
- **Adapters:** `mock` for tests, `remote` for a development tenant, and `runtime`,
  which is configured from the request the function serves.
- **Errors:** `HttpError` with stable codes. Refusals from the data plane are mapped
  to a status and a code, and no table or constraint name reaches a caller.
- **Router:** `Router\App` and `Router\RouteContext`. Answers are written as real
  JSON lists and objects.
- **One-line CRUD:** `Router\Crud::mount`. A list filter that cannot be applied is
  refused, never ignored.
- **Who is acting:** `Router\Actor`.
- **Settings:** `Settings`, resolved per market, cached, and falling back to your
  defaults when the platform cannot be reached.
- **The App's own events:** `events()->emit()`, recorded instead of published on the
  `mock` adapter.
