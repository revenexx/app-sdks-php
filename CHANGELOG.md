# Changelog

All notable changes to `revenexx/app-sdk` are listed here. The package follows
[semantic versioning](https://semver.org).

## 1.2.0

- **`Trash::mount` gives an entity with `trash: true` its trash in one call**, the same
  as `mountTrash` in the JavaScript SDK (PE-1022). `DELETE <path>/{id}` puts a live row
  into the trash with its live children as one group and deletes a trashed row for
  good; `POST <path>/{id}/restore` brings the group back. `guard` refuses the trash
  (`409 trash_blocked`), `blockers` refuse the final delete (`409 delete_blocked`),
  `canDeleteForGood` can require its own permission (`403 not_permitted`). A check that
  throws blocks.
- **`$trash->purge($caller)`** is the scheduled job. It reads `trash_retention_days`
  through the gateway (`GET /v1/audit/settings`, 90 when unset;
  `REVENEXX_AUDIT_SETTINGS_URL` overrides the URL), deletes for good what is older
  through the same blockers, and writes the reason a row stays into
  `purge_blocked_reason`. When the retention cannot be read the run is skipped.
- **`GatewayCounter`** counts another App's records through the gateway for a cross-app
  blocker. A count it cannot get is an `unverifiable` blocker.

## 1.1.1

Documentation only; no change in behaviour.

- **README rewritten for App developers.** It covers installation, generating the
  typed client with the revenexx CLI, a function entrypoint, the data client and
  queries, adapters, the router and one-line CRUD, errors and their codes, settings,
  the acting person, events, testing on the mock and versioning.
- **The compare-and-set retry loop** for an atomic decrement with `updateIf`.
- **Docblocks** refer to the JavaScript SDK by name.
- **`composer test` passes in a checkout of this package alone.** The tests that need
  the cases shared with the JavaScript SDK are skipped there.

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
