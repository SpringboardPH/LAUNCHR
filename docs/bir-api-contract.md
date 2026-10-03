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
  "employee_id": null,
  "employee_name": null,
  "status": "draft",
  "version": 1,
  "parent_id": null,
  "prepared_by": { "id": 5, "name": "Jane Dela Cruz" },
  "approved_by": null,
  "rejection_reason": "Item 25 total tax withheld does not match the payroll register — please recheck May.",
  "fields": { },
  "validation_errors": [ ]
}
```

`parent_id` is always present. It is null unless the draft was created by
`revise`, in which case it points at the finalized draft it corrects.

| Key | Notes |
|---|---|
| `form_type` | `1601-C` or `2316`. Exactly these strings — they match `Form1601CSchema::FORM_TYPE` and `Form2316Schema::FORM_TYPE`. |
| `period` | `YYYY-MM` for 1601-C, `YYYY` for 2316. See §7 — this is the weakest part of the shape. |
| `employee_id` | Null for a 1601-C, which covers all employees. Set for a 2316, which is per employee. |
| `employee_name` | `"Last, First"`. Present only when the employee relationship is loaded; null if the draft has no employee. |
| `status` | See §4 |
| `version` | Starts at 1. Incremented by `revise`. |
| `parent_id` | `id` of the finalized draft this one corrects, set by `revise`. Null on an original draft. |
| `prepared_by` | `{ id, name }` of whoever created the draft |
| `approved_by` | `{ id, name }` or null. Null on a new revision. |
| `rejection_reason` | Text a reviewer gave when returning the draft, else null |

---

## 3. Field entries

Each entry under `fields` looks like this:

```json
"total_taxes_withheld": {
  "value": "55010.00",
  "origin": "user",
  "edited": true,
  "system_value": "54872.50",
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

**Who may approve:** accounting only, confirmed by the supervisor. Admin and HR
users cannot approve. The system also will not let a preparer approve their own
form: `approve` is refused when the caller is the draft's `prepared_by`.
Neither rule is enforced yet. The `bir` routes still allow admin, HR and
accounting. Role tightening is scheduled for Week 7 and the self-approval check
for Week 6.

**Assumption:** the two-person rule assumes the client has at least two
accounting users. If one accounting user both prepares and approves, nobody can
approve their forms. This needs a decision: either admin becomes a fallback
approver, or the two-person rule is dropped for that deployment.

---

## 6. Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/bir/config` | Feature flag, form types, status flow |
| GET | `/bir/drafts` | Paginated list, filterable by `status` and `form_type` |
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

### GET /bir/drafts

Paginated, newest first. Query parameters:

| Parameter | Notes |
|---|---|
| `status` | Optional filter |
| `form_type` | Optional filter |
| `per_page` | Drafts per page. Default 15, clamped to 1–100. |
| `page` | Page number, starting at 1. Default 1. |

`data` is a flat array of draft objects (§2) for the requested page only. A
sibling `pagination` object describes the full result, in the same format as the
other paginated lists in the app:

```json
{
  "success": true,
  "data": [ ],
  "pagination": {
    "total": 42,
    "count": 15,
    "per_page": 15,
    "current_page": 1,
    "last_page": 3
  },
  "message": "BIR drafts retrieved"
}
```

`total` is every draft matching the filters; `count` is how many are on this
page. Counting, filtering or searching `data` in the browser only covers the
current page — use the query parameters instead.

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

Every endpoint reads and writes the `bir_form_drafts` table. These gaps are
known, not bugs — but check here before reporting one.

**Some settings-sourced fields still come back `pending`.** The nine
`source: settings` fields on the 1601-C (items 5–12, including 9A) and the five
on the 2316 (items 12–15, including 14A) are filled from `system_settings` with
`origin: settings`. Blanks, seed placeholders and values outside a field's
allowed options are left out on purpose. Those fields stay `value: null`,
`origin: pending`, so the user is asked for them and they never appear on a
form as real values. In a freshly seeded database most company details are
still placeholders. They show as `pending` until someone enters the real
registration details.

**`PUT` does not populate `system_value`, `edited_by` or `edited_at` yet.** It
sets `origin: user` and `edited: true` only. The fixtures show the full shape;
the behaviour is Week 6.

**A finalized form can be revised more than once.** Nothing stops a second
`revise` on the same finalized draft, so two drafts can end up with the same
`version` and the same `parent_id`. Whether competing corrections should be
allowed is an open question for the accountant.

**`validation_errors` is never cleared or recalculated when a field changes.**
A draft can show an error about a field that has since been corrected or
emptied. Validation is Week 5.

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
