# BIR Form Assistant — API contract

**Owner:** Dev B · **Week 1, updated Week 5** · Branch `BIRsystems-ollama`

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
| 400 | Illegal status move, or a form that is locked (approved or finalized) |
| 403 | Caller's role cannot reach `/bir` (employees), or the caller prepared the form they are trying to approve |
| 404 | Draft not found |
| 422 | Request body failed validation, or `submit`/`approve` refused a form that fails validation (§6) |
| 501 | Not built yet (export) |

400 for illegal status moves matches `LeaveController::approve`, which returns
400 for "Only pending leave requests can be approved".

A 422 from `submit` or `approve` is "fix the form", not "bad request": it carries the draft in
`data`, with `validation_errors` filled in. Tell it apart from a 400, which means
the move itself is not allowed from the current status.

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

### When `PUT` marks a field edited

Only an answer to a field the system supplies (schema `source` `payroll` or
`settings`) is an override: `edited: true`, `edited_by` the person saving it,
`edited_at` the system time (`SystemClock`, Manila offset), and `system_value`
the figure it replaced. That figure is `null` when the field was a gap, such as
a company TIN still unset. Edited again, `system_value` keeps the original
calculated figure and `edited_by`/`edited_at` show the latest change; the audit
log holds the full history.

An answer to a field only a person answers (`source: user`, e.g. "Amended
Return?") replaces nothing: `origin: user`, `edited: false`, no history.

`edited_by.name` is the name at the time of the edit, so it stays as it was if
the user is renamed later.

**Clearing a field (`null`)** depends on whether the system has a figure for it:

- An overridden `payroll` or `settings` field gets its calculated figure back:
  `value` is the old `system_value`, `origin` is the schema source,
  `edited: false`, and `system_value`, `edited_by` and `edited_at` are null.
  Clearing means "undo my override".
- A `payroll` or `settings` field still holding its calculated figure is left
  exactly as it is.
- Only a field with no calculated figure (a gap, such as a company TIN still
  unset, even after someone answered it) or a field only a person answers
  (`source: user`) goes back to `pending`.

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
[
  {
    "field": "total_taxes_withheld",
    "code": "payroll_mismatch",
    "severity": "warning",
    "message": "\"Total Taxes Withheld\" is 55,010.00, but the payroll this draft was built from withheld 54,872.50."
  }
]
```

| Key | Meaning |
|---|---|
| `field` | Always a key in `fields`, so Dev D can anchor the message to the row that caused it |
| `code` | Which rule failed (table below) |
| `severity` | `error` blocks `submit`; `warning` is shown but never blocks |
| `message` | Plain-language explanation, ready to show the user |

**Read entries by key, never by position.** `validate` returns them in the order
above, but MySQL re-sorts object keys, so `GET /bir/drafts/{id}` returns the same
entry with its keys in a different order. Entries come back sorted in
printed-form order.

| `code` | Severity | Meaning |
|---|---|---|
| `required` | error | A required question nobody has answered |
| `condition` | error | Required because of another answer; the message names it |
| `record_gap` | error | Should come from payroll or company settings, but nothing was there. Fix the record, or enter it on the draft. |
| `total_mismatch` | error | A total does not equal the sum of its parts on the form as it stands (8 totals per form) |
| `invalid_amount` | error | An amount that is not plain decimal text: commas, currency signs or words. `PUT` refuses these already (§6); this still catches one held over from older data. |
| `payroll_mismatch` | warning | Tax withheld (1601-C item 25, 2316 item 25A) differs from the payroll the draft was built from |
| `empty_period` | warning | The draft was built from a period with no finalized or paid payroll, so every payroll figure is zero |

`payroll_mismatch` and `empty_period` are warnings only until the accountant
answers two open questions (§7). Every other code is an error. Changing a code's
severity is one line, `WARNING_CODES` in `BirDraftValidator`.

`validation_errors` is the **last known result**, not live truth. Validation runs
only on `validate` and `submit` (§6), never on `PUT` or `GET`, so after an edit
call `validate` to bring it up to date.

---

## 4. Field keys

**The authoritative key list is Dev A's schemas**, not this document and not the
fixtures:

- `App\Services\BIR\Schemas\Form1601CSchema` — 40 fields, items 1–36 of the
  printed form
- `App\Services\BIR\Schemas\Form2316Schema` — 79 fields

Both expose `fields()`, `byKey()` and `payrollDerivedKeys()`; the 2316 schema
also has `unverifiedKeys()`. Each field carries `key`, `item`, `label`, `type`,
`source`, `required`, `rule`, `pdf_anchor` and `guidance` (help text), plus
`options` on enums and `required_when` on conditional fields. Both schemas are
served on `GET /bir/config` (§6).

**Dev D: do not hardcode field keys.** Read them from the draft response and use
the schema's `label`, `item` and `guidance` for display. The fixtures carry a handful of
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

Any other move returns 400 naming the current status. `submit` and `approve`
also check the form first and refuse it with 422 if it has any `severity: error`
entry (§6). Fields can be edited in `draft` and `pending` only; an approved or
finalized form is locked. A finalized form is never edited or moved;
corrections start a new version and leave the original as filed.

**Who may approve:** accounting only, confirmed by the supervisor. Admin and HR
users cannot approve. The system also will not let a preparer approve their own
form: `approve` is refused with 403 when the caller is the draft's
`prepared_by`. The self-approval rule is enforced. The accounting-only rule is
not yet: the `bir` routes still allow admin, HR and accounting to approve, and
role tightening is scheduled for Week 7.

**Workflow, as decided:** a preparer fills in the form and submits it.
Accounting reviews the submitted form, may edit it, and approves it in the same
step. There is no send-back just to fix a figure. The supervisor confirmed that
the approver is never the person who prepares forms, so the self-approval rule
never leaves a form with no one able to approve it. The approver's edits are
recorded like any other (`edited_by`, §3), and `approve` validates again, so an
edit that breaks the form blocks approval until it is fixed.

---

## 6. Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/bir/config` | Feature flag, form types, status flow, both form schemas |
| GET | `/bir/drafts` | Paginated list, filterable by `status` and `form_type` |
| POST | `/bir/drafts` | Create |
| GET | `/bir/drafts/{id}` | One draft |
| PUT | `/bir/drafts/{id}` | Edit field values |
| POST | `/bir/drafts/{id}/validate` | Run the checks and store `validation_errors` |
| POST | `/bir/drafts/{id}/submit` | draft → pending, if the draft passes validation |
| POST | `/bir/drafts/{id}/approve` | pending → approved, if the form passes validation |
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
  },
  "schemas": {
    "1601-C": [ { "key": "return_period", "item": "1", "label": "For the Month (MM/YYYY)", "guidance": "…" } ],
    "2316": [ ]
  }
}
```

`enabled` reads `bir_forms_enabled` from system settings and is false by
default — the Week 8 feature switch. `status_flow` is served from the same
constant the controller enforces, so Dev D can drive button visibility from it
rather than hardcoding the transitions.

`schemas` is each schema's `fields()` (§4), every field with all its keys
(shortened above). The screen reads keys, labels, item numbers and help text
from here instead of keeping its own copy.

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

Editing is allowed in `draft` and `pending` status, so the approver can correct
a submitted form before approving it (§5). A pending form stays `pending`. An
approved or finalized form returns 400 "Only draft and pending forms can be
edited; this form is in approved status".

After merging the answers, `PUT` recalculates the form's totals (Dev A's
`BirFormMapper::recalculate()`), so entering a surcharge updates items 35 and 36
in the same response. A total someone typed by hand (`edited: true`) is never
overwritten, and the totals after it are worked out from the typed value.
Clearing it (`null`) hands it back to the calculation. Clearing any other
figure follows §3: an override is undone, and a calculated figure is never
blanked. A typed total that
doesn't add up is reported by `validate` as `total_mismatch`.

Each answer is checked as it is saved. Every key must be a field on the draft's
form, and each value must suit the field's type (§4 schemas):

| Type | Accepted |
|---|---|
| decimal | Plain decimal text (`"1500"`, `"20.5"`, `"-250.00"`) or a JSON number |
| boolean | `true`/`false`, `"true"`/`"false"`, `"1"`/`"0"`, `"yes"`/`"no"`; stored as `true`/`false` |
| enum | One of the field's `options` |
| integer | A whole number, e.g. `2026` |
| month | `MM/YYYY`, e.g. `09/2026` |
| date | A real date as `YYYY-MM-DD` |
| string / text | Text, at most 255 / 1000 characters |
| manual | Never: signed on the printed form, not entered here |

`null` is always accepted and clears the field. One bad answer refuses the whole
request with 422, and nothing in it is saved:

```json
{
  "message": "\"Surcharge\" must be an amount like 1234.50, without commas or a currency sign.",
  "errors": {
    "fields.surcharge": ["\"Surcharge\" must be an amount like 1234.50, without commas or a currency sign."]
  }
}
```

Each key in `errors` is `fields.<field key>`, so the message can be shown on the
row and Dev C's prompts can re-ask that question. The same checks apply to a
pending form. A missing draft is still 404 and a locked form still 400, whatever
the body.

`PUT` checks single answers only. Whether the draft as a whole is complete and
adds up is `validate`'s job: call it afterwards to refresh `validation_errors`.

### POST /bir/drafts/{id}/validate

No request body. Runs every check in §3 on the draft, stores the result on
`validation_errors`, and returns the draft.

| Code | Response |
|---|---|
| 200 | `data` is the draft (§2) with `validation_errors` freshly filled in. `message` counts both, e.g. "Draft validated: 3 errors, 1 warning". |
| 400 | "Finalized forms are locked and can't be revalidated". Allowed in `draft`, `pending` and `approved`. |
| 404 | "Draft not found" |

Cheap to call repeatedly: when the result has not changed, nothing is written
and no audit row is added. Dev C's prompting loop calls it after each saved
answer and takes its "still needs your input" count from the entries with
`severity: error`, which also covers totals and invalid amounts, not just blanks.

### POST /bir/drafts/{id}/submit

No request body. Checks the move first, then validates:

| Code | When |
|---|---|
| 400 | The draft is not in `draft` status. Nothing is validated or changed. |
| 422 | The draft has at least one `severity: error` entry. `success: false`, the draft in `data` with `validation_errors` filled in, and a message like "Draft has 3 errors; fix them before submitting". The draft stays in `draft`. |
| 200 | Warnings only, or nothing: the draft moves to `pending`. Any warnings stay on the draft for the reviewer. |

The validation result is stored in every case except the 400, so a refused draft
shows why.

### POST /bir/drafts/{id}/approve

No request body. Works like `submit`: the approver may have edited the form
since it was submitted, so it is checked again.

| Code | When |
|---|---|
| 400 | The form is not in `pending` status. Nothing is validated or changed. |
| 403 | The caller prepared this form: "You prepared this form, so someone else must approve it". Checked before validating, so nothing is validated or changed. |
| 422 | At least one `severity: error` entry. Same shape as `submit`'s 422, with "Draft has 1 error; fix it before approving". The form stays `pending` and `approved_by` stays null. |
| 200 | Warnings only, or nothing: the form moves to `approved` and `approved_by` is the caller. |

### POST /bir/drafts/{id}/reject

```json
{ "reason": "Item 25 does not match the payroll register for May." }
```

Required, max 1000 characters. 422 if missing.

### POST /bir/drafts/{id}/revise

Only on a finalized draft, else 400. Returns 201 with a copy in `draft` status,
`parent_id` set to the original, `version` one above the highest version of the
same form (form type, period and employee) so two drafts never share a number, `approved_by` and
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

**Any finalized version can be revised, any number of times.** Decided: there is
no limit on revisions. Versions never repeat — revising the same filed v1 twice
gives v2 then v3, both with `parent_id` pointing at v1. Still open with the
accountant: whether "no limit" means a chain, where only the latest finalized
version may be revised. If so, `revise` will refuse older versions; the
numbering stays as it is.

**`payroll_mismatch` and `empty_period` are warnings, pending the accountant.**
On the real filed 2316s, item 25A is 0 for every employee even though the
1601-Cs show tax withheld, so it is open whether 25A should be 0 when tax is
refunded at year-end. It is also open whether a nil 1601-C has to be filed for a
month with no payroll. Until both are answered these two codes never block
`submit` (§3).

**Week 4 real-form check.** LAUNCHR's 1601-C (two months) and 2316 (19
employees) were compared with real filed returns, kept outside the repo. Every
computed total matches; the remaining differences are classification questions
pending with the accountant (Dev A holds the list).

**Night differential is missing from payroll since 14 July 2026.** It was
switched off in payroll generation that day and stays off by decision. Drafts
are built from payroll, so night-shift employees' pay and withheld tax come out
without it, and the 2316 MWE night differential (item 32,
`nontax_mwe_night_diff`) only counts payrolls from before that date. Not a BIR
bug. If the accountant pays it outside LAUNCHR, expect a difference when
comparing against a filed form.

**Signatures are signed by hand.** Items 55 and 56 on the 2316 (`manual`) are
refused by `PUT`, and items 53 and 54 hold only the date signed. An e-signature
for the employer/authorized agent is proposed (a separate `sign` endpoint for
accounting on approved forms), pending confirmation that BIR and the client
accept it.

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
shape at once. Its stored validation error predates `code` and `severity`;
calling `validate` on it replaces it with the current shape.

The fixtures hold only a handful of fields, so every one of them fails
`validate` with many `required` and `record_gap` entries, `submit` on draft 1 or
5 returns 422, and so does `approve` on draft 2. That is expected.
