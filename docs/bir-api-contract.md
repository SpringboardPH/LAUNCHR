# BIR Form Assistant — API contract

**Owner:** Dev B · **Week 1** · Branch `BIRsystems`

What Dev C and Dev D build against. Everything here describes the stub as it
actually behaves today — verified against the running app, not aspirational.
Section 7 lists what is knowingly incomplete and when it changes.

If something here has to change after sign-off, raise it on Friday under
"changes a shared shape".

---

## 1. Conventions

All routes are under `/api/bir`, behind `auth:sanctum` and
`role:admin,hr,accounting`. Employees cannot reach any of them.

Every response uses the envelope already used across LAUNCHR:

```json
{ "success": true,  "data": { }, "message": "Draft retrieved" }
{ "success": false, "message": "Cannot move a form in draft status to finalized" }
```

### Status codes

| Code | When |
|---|---|
| 200 | Success |
| 201 | Draft or revision created |
| 400 | Illegal status move |
| 404 | Draft not found |
| 422 | Request body failed validation |
| 501 | Not built yet (export) |

400 for illegal status moves matches `LeaveController::approve`, which returns
400 for "Only pending leave requests can be approved".

---

## 2. The draft object

```json
{
  "id": 5,
  "form_type": "1601-C",
  "period": "2026-05",
  "status": "draft",
  "version": 1,
  "prepared_by": { "id": 5, "name": "Jane Dela Cruz" },
  "approved_by": null,
  "rejection_reason": "Item 25 total tax withheld does not match the payroll register — please recheck May.",
  "fields": { },
  "validation_errors": [ ]
}
```

`parent_id` appears only on a draft created by `revise`, pointing at the
finalized draft it corrects.

| Key | Notes |
|---|---|
| `form_type` | `1601-C` or `2316`. Exactly these strings — they match `Form1601CSchema::FORM_TYPE` and `Form2316Schema::FORM_TYPE`. |
| `period` | `YYYY-MM` for 1601-C, `YYYY` for 2316. See §7 — this is the weakest part of the shape. |
| `status` | See §4 |
| `version` | Starts at 1. Incremented by `revise`. |
| `prepared_by` | `{ id, name }` of whoever created the draft |
| `approved_by` | `{ id, name }` or null. Null on a new revision. |
| `rejection_reason` | Text a reviewer gave when returning the draft, else null |

---

## 3. Field entries

Each entry under `fields` looks like this:

```json
"total_taxes_withheld": {
  "value": "55,010.00",
  "origin": "user",
  "edited": true,
  "system_value": "54,872.50",
  "edited_by": { "id": 5, "name": "Jane Dela Cruz" },
  "edited_at": "2026-06-08T09:47:00+08:00"
}
```

| Key | Meaning |
|---|---|
| `value` | What goes on the form. `null` means not known yet. |
| `origin` | Where *this* value came from: `payroll`, `settings`, `user`, `pending` |
| `edited` | `true` once a person replaced a calculated value |
| `system_value` | The figure the system calculated before a person changed it |
| `edited_by` | `{ id, name }` of whoever changed it, else null |
| `edited_at` | ISO 8601 timestamp of that change, else null |

### `origin` is not the schema's `source`

Dev A's schemas also have a `source` on every field. **They mean different
things and must not be confused.**

- Schema `source` — where a field is *supposed* to come from. Static, the same
  for every draft. Values: `payroll`, `settings`, `user`.
- Draft `origin` — where this draft's value *actually* came from right now.
  Runtime state, changes as the draft is filled in. Values: the same three,
  plus `pending`.

A field with schema `source: payroll` starts life with `origin: pending`, moves
to `origin: payroll` once aggregation fills it, and moves to `origin: user` if
someone overrides it.

### Three invariants

These always hold. Build on them.

1. **`value` is null ⟺ `origin` is `pending`.** A null value never claims a
   real origin, and a filled value is never `pending`. So "what is still
   missing?" is one check: `origin === 'pending'`. Dev C's prompting loop
   should use this rather than testing for null.
2. **`edited: true` ⟹ `origin` is `user`**, and `system_value` holds the
   figure that was replaced. A person typed the current value; the calculation
   it overrode is preserved beside it.
3. **`system_value`, `edited_by` and `edited_at` are all null unless `edited`
   is true.**

### Validation errors

```json
[ { "field": "total_taxes_withheld", "message": "Does not match calculated payroll total." } ]
```

`field` always matches a key in `fields`, so Dev D can anchor the message to the
row that caused it.

---

## 4. Field keys

**The authoritative key list is Dev A's schemas**, not this document and not the
fixtures:

- `App\Services\BIR\Schemas\Form1601CSchema` — 40 fields, items 1–36 of the
  printed form
- `App\Services\BIR\Schemas\Form2316Schema` — 74 fields

Both expose `fields()`, `byKey()` and `payrollDerivedKeys()`; the 2316 schema
also has `unverifiedKeys()`. Each field carries `key`, `item`, `label`, `type`,
`source`, `required`, `rule` and `pdf_anchor`.

**Dev D: do not hardcode field keys.** Read them from the draft response and use
the schema's `label` and `item` for display. The fixtures carry a handful of
keys only, as sample data — a real draft will have all of them.

---

## 5. Status flow

```
draft ──submit──▶ pending ──approve──▶ approved ──finalize──▶ finalized
  ▲                   │                                            │
  └─────reject────────┘                                     (locked)
                                                                   │
                                                          revise creates
                                                          version N+1 with
                                                          parent_id set
```

| From | To | Endpoint |
|---|---|---|
| draft | pending | submit |
| pending | approved | approve |
| pending | draft | reject (reason required) |
| approved | finalized | finalize |
| finalized | — | none — `revise` creates a new draft instead |

Any other move returns 400 naming the current status. A finalized form is never
edited or moved; corrections start a new version and leave the original as
filed.

**Open:** who may approve. Today any admin, HR or accounting user can. Narrowing
this is waiting on the supervisor meeting. Separately, from Week 6 a preparer
will not be able to approve their own draft.

---

## 6. Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/bir/config` | Feature flag, form types, status flow |
| GET | `/bir/drafts` | List, filterable by `status` and `form_type` |
| POST | `/bir/drafts` | Create |
| GET | `/bir/drafts/{id}` | One draft |
| PUT | `/bir/drafts/{id}` | Edit field values |
| POST | `/bir/drafts/{id}/submit` | draft → pending |
| POST | `/bir/drafts/{id}/approve` | pending → approved |
| POST | `/bir/drafts/{id}/reject` | pending → draft |
| POST | `/bir/drafts/{id}/finalize` | approved → finalized |
| POST | `/bir/drafts/{id}/revise` | New version from a finalized draft |
| GET | `/bir/drafts/{id}/export` | PDF. 501 until Dev C builds it. |
| POST | `/bir/chat` | Dev C. Controller method is `message`. |

### GET /bir/config

```json
{
  "enabled": false,
  "form_types": ["1601-C", "2316"],
  "status_flow": {
    "draft": ["pending"],
    "pending": ["approved", "draft"],
    "approved": ["finalized"],
    "finalized": []
  }
}
```

`enabled` reads `bir_forms_enabled` from system settings and is false by
default — the Week 8 feature switch. `status_flow` is served from the same
constant the controller enforces, so Dev D can drive button visibility from it
rather than hardcoding the transitions.

### POST /bir/drafts

```json
{ "form_type": "1601-C", "period": "2026-07" }
```

Both required. 201 with the new draft.

### PUT /bir/drafts/{id}

```json
{ "fields": { "total_taxes_withheld": "41000.00" } }
```

Flat key to value. The server sets `origin`, `edited` and the provenance keys —
Dev D never sends those.

### POST /bir/drafts/{id}/reject

```json
{ "reason": "Item 25 does not match the payroll register for May." }
```

Required, max 1000 characters. 422 if missing.

### POST /bir/drafts/{id}/revise

Only on a finalized draft, else 400. Returns 201 with a copy in `draft` status,
`version` + 1, `parent_id` set to the original, `approved_by` and
`rejection_reason` cleared, `prepared_by` set to the current user. The original
is untouched.

---

## 7. What is not real yet

Week 1 is a stub. These are known, not bugs — but check here before reporting
one.

**Nothing persists.** Every response is built from an in-memory array in
`BirFixtureSeeder::fixtures()`. Submit a draft, read it back, and it is still
`draft`. The `bir_form_drafts` table lands in Week 2; the shape above does not
change when it does.

**`store` and `revise` both return id `999`,** which then 404s on a subsequent
GET. Create-then-open does not work against the stub.

**`store` returns an empty `fields` object.** Real drafts get their fields from
Dev A's mapper in Week 2–4.

**`PUT` works on any status.** It should only work while the draft is in
`draft`. The guard lands with the real model in Week 2.

**`PUT` does not populate `system_value`, `edited_by` or `edited_at` yet.** It
sets `origin: user` and `edited: true` only. The fixtures show the full shape;
the behaviour is Week 6.

**The list is not paginated.** `data` is a flat array of everything. When
pagination arrives it will follow `LeaveController::index` — `data` stays a flat
array and a sibling `pagination` object appears beside it, so nothing Dev D
builds now breaks.

**Money in the fixtures is formatted with commas** (`"55,010.00"`). Real values
will be plain decimal strings (`"55010.00"`) so Week 5 validation can compare
them numerically. Dev D should format for display rather than assume the server
has.

**Some fixture field keys are placeholders** and do not all appear in Dev A's
schemas — `gross_compensation` is in the fixtures, but the 1601-C schema calls
that item `total_compensation`. Use the schema, not the fixtures, as the key
list.

**The 2316 fixtures have no employee attached.** A 2316 is per employee, and
drafts 3 and 4 are only distinguishable by TIN. The draft will carry the
employee once the table exists.

**`/bir/chat` errors** until Dev C creates `BirChatController` with a `message`
method.

---

## 8. Testing against the stub

```powershell
# start the backend
cd backend
php -S 127.0.0.1:9000 -t public public/index.php

# log in (OTP is off in the demo seed)
curl.exe -X POST http://127.0.0.1:9000/api/login -H "Content-Type: application/json" -H "Accept: application/json" -d '{\"email\":\"accounting@springboardph.com\",\"password\":\"password\"}'

# then every request carries the token
$t = "paste-token"
curl.exe http://127.0.0.1:9000/api/bir/drafts -H "Authorization: Bearer $t" -H "Accept: application/json"
```

Requires `php artisan migrate --seed`, then `db:seed --class=DemoPeopleSeeder`
and `--class=DemoOpsSeeder` — `DatabaseSeeder` does not call the demo seeders.

### The five fixtures

| id | Form | Period | Status | What it demonstrates |
|---|---|---|---|---|
| 1 | 1601-C | 2026-07 | draft | Two fields still `pending` |
| 2 | 1601-C | 2026-06 | pending | Complete, one edited field |
| 3 | 2316 | 2025 | approved | Awaiting finalization |
| 4 | 2316 | 2025 | finalized | Locked, version 2 |
| 5 | 1601-C | 2026-05 | draft | Returned by reviewer — carries a rejection reason, a validation error, and an edited field whose `system_value` explains the error |

Draft 5 is the most useful one to build against: it exercises every part of the
shape at once.
