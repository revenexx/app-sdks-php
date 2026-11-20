# Changelog

All notable changes to `revenexx/app-sdk` are listed here. The package follows
[semantic versioning](https://semver.org).

## Unreleased

The next release is **2.1.0**: it adds a call and keeps every 2.0 promise.

- **`createMany($entity, $rows)` inserts a list of records in one request.** PostgREST
  inserts a JSON array in one statement, and Apps write sets that way. Every record is
  checked like one create before anything is sent: a key that is not a column is
  `400 unknown_field`, a record with no field `400 empty_create`, one that is not a
  record or names other fields than the first `400 bad_request`, each naming the record
  in `details['index']`. An empty list is `400 empty_create`. It answers the created
  rows, in order. On the mock, a primary key twice in the list or one already stored is
  `409 unique_violation`, nothing stored. An adapter takes part by implementing the new
  `CreatesMany` interface; the client never emulates it. `create()` still takes one
  record (`data-client` AC-18).
- **The mock keeps its seed when the first call is a create or a remove.** Either one,
  before any read, wrote past the seed, so the seeded rows were gone or came back.

## 2.0.0

The first entry narrows every write, and a narrowing
is a major (`docs/versioning.md`). A `Crud::mount` route or a mock-backed test that
wrote a key the entity does not declare starts failing with it.

2.0.0 is also the first release of what was listed here as 1.2.0 — the trash, its
purge, `GatewayCounter` and the two audit headers. That version was never tagged, so
an App on `^1.1` has none of it; the entries are below, after the 2.0.0 changes.

- **Breaking — a write names only the columns the App declares.** `create`, `update` and
  `updateIf` — and therefore `POST` / `PUT` on a `Crud::mount` collection — refuse a
  key that is not a column of the entity with `400 unknown_field`, naming every such
  key in `details['unknown']` (and the writable columns in `details['allowed']`),
  before any request. Refused, never dropped. Any document inside a `jsonb` column,
  `tenant_id`, the system columns the schema declares and entities without column
  metadata are unchanged. The columns `trash: true` and `external_id: true` add are
  writable whenever the entity carries the flag (`new Entity(..., trash: true,
  externalId: true)`), declared or not. The mock and remote adapters refuse the same
  keys when used directly. Same as the JavaScript SDK (`data-client` AC-13 to AC-15).
- **Breaking — a write is one record.** `create`, `update` and `updateIf` on an entity
  with column metadata, and `POST` / `PUT` on a `Crud::mount` collection, refuse a list
  — a non-empty array keyed exactly `0` … `n-1`, which is what both `[…]` and
  `{"0":…}` decode to — with `400 bad_request` and the message `a write takes one
  record, as a JSON object — not a list`, before any request. `[]` stays an empty
  record.
- **A create with no field is refused.** `create($entity, [])` (also what `{}` decodes
  to), and `POST []` / `POST {}` on a `Crud::mount` collection, answer `400
  empty_create` (`request body must contain at least one field to create`) on the mock
  and the remote adapter alike, before any request. The remote adapter used to send
  `[]`, which PostgREST read as a bulk insert of no rows, and answered `500
  empty_representation`; the mock stored a row of defaults (`data-client` AC-17). A list used to reach the mounted routes as keys `0`, `1`, … and fail as
  `unknown_field`; a handler that passed `$c->body` on was the same. Same as the
  JavaScript SDK (`data-client` AC-16).
- **A mounted list filters on the columns the entity's flags add.** `Crud::mount`
  accepts the trash columns (`trash: true`) and `external_id` (`external_id: true`) as
  list filters whether or not `columns` names them, and names them in
  `details['allowed']` of an `unknown_filter`, so a list accepts every column a write
  may name (`list-filters` AC-9). A key the App claims in `queryParams` stays the
  App's key.
- **Breaking — a mounted trash entity does not take the trash columns in a body.** On
  an entity with `trash: true`, `POST` / `PUT` of `Crud::mount` refuse a body that sets
  `deleted_at`, `deleted_by`, `deleted_by_id`, `trash_batch` or `purge_blocked_reason`
  (declared or not, `null` included) with `400 read_only_field`, naming them in
  `details['fields']`. Only `Trash::mount` writes them, through the client.
  `external_id` stays writable (`crud-routes` AC-11).
- **A value list in `where` keeps each value whole.** `['col' => [a, b]]` and
  `['op' => 'in', 'value' => [a, b]]` send every value double-quoted, with `"` and
  `\` inside it escaped, the way PostgREST reads a list. A value with a comma used
  to be split in two and match nothing; one with a parenthesis broke the filter
  and failed the whole read. Same as the JavaScript SDK (`query-language` AC-9).
- **`$c->actor->rep`** and plane **`rep`**: a sales rep the gateway resolved
  (`x-revenexx-caller: rep` with `x-revenexx-rep`, ADR-0132 §9). Trust is
  always `asserted`. The rep code arrives percent-encoded as UTF-8 and is
  decoded with `rawurldecode`; a code that does not decode is no rep. A rep
  without a code is no rep and holds no permission, and a call that says `rep`
  ignores the contact headers, with or without a code. `Actor::HEADERS` gains
  `caller` and `rep`. Same promises as the JavaScript SDK (`acting-person` AC-8
  to AC-11).
- **`$settings->set($c, $values, ifAbsent: true)`** and **`$c->setSettings($values, true)`**
  store the App's own tenant-level settings through the gateway
  (`PATCH /v1/settings/apps/{app}`), forwarding the platform's
  `x-revenexx-app-context`. Never throws: `['ok' => false, …]` on a refusal, an
  unreachable gateway, or a request without an app context (event/schedule runs). A
  write that stored something drops the tenant's cached reads. `writeTransport` is the
  test seam.
- **`stored`** on `load()` names the keys the tenant stored rather than defaulted.

Listed as 1.2.0 before, never tagged, released with 2.0.0:

- **`Trash::mount` gives an entity with `trash: true` its trash in one call**, the same
  as `mountTrash` in the JavaScript SDK. `DELETE <path>/{id}` puts a live row
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
- **The `runtime` adapter forwards the request's `X-Revenexx-Audit`** to the data plane
  unchanged with every request, like `X-Revenexx-Market`. Baseline uses it to
  name the person behind a call in the record history; the SDK never reads it. Without
  one, or with a value that is not one line of visible ASCII, no header is sent and
  nothing else changes. `RemoteAdapter` takes it as the named argument `audit:`.
  `Actor` is unchanged.
- **The `runtime` adapter forwards the request's `X-Revenexx-Audit-Note`** to the data
  plane unchanged with every request, next to `X-Revenexx-Audit`. It says what a change
  is and why (URI-encoded JSON `{"title":"…","reason":"…"}`, sent by the Cockpit on a
  rollback); Baseline stores it with the history entry. The SDK never reads it and it
  never changes who is acting. Over 8192 bytes, or not one line of printable ASCII, it
  is dropped and nothing else changes. An App describing its own change passes
  `'auditNote' => …` in the runtime config (empty sends none); `RemoteAdapter` takes the
  named argument `auditNote:`.

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
