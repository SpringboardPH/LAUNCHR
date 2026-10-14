# BIR Form Assistant — API contract

**Owner:** Dev B · **Week 1, updated Week 7** · Branch `BIRsystems-ollama`

What Dev C and Dev D build against. Everything here describes the stub as it
actually behaves today — verified against the running app, not aspirational.
Section 7 lists what is knowingly incomplete and when it changes.

If something here has to change after sign-off, raise it on Friday under
"changes a shared shape".

---

## 1. Conventions

All routes are under `/api/bir`, behind `auth:sanctum` and
`role:admin,hr,accounting`. Employees cannot reach any of them. `approve`,
`reject` and `finalize` also need `role:accounting`, and only accounting may
`PUT` to a pending or approved form (§5). The two switch routes, `PUT /bir/switch` and
`PUT /bir/switch/password`, are admin-only and allow 5 tries a minute (§6).

**Feature switch:** while the system setting `bir_forms_enabled` is off (or has
no row), every route except `GET /bir/config`, `PUT /bir/switch` and
`PUT /bir/switch/password` returns 403
`{ "success": false, "message": "The BIR Form Assistant is not enabled" }` to
every role, admin included, and changes nothing. Config stays open so the screen
can read `enabled` and hide the page; the switch routes stay open, or BIR could
never be turned on (§6).

`bir_forms_enabled` changes only through `PUT /bir/switch`: an admin, with the
company password. The company password is one shared password, not anyone's
login, set by an admin in the app through `PUT /bir/switch/password`. Only its
bcrypt hash is stored, in the setting `bir_switch_password_hash`, and no endpoint
ever returns it. The general settings save (`PUT /api/admin/settings/{key}`)
refuses both keys with 403 "Use the BIR switch, which asks for the company
password". `GET /api/admin/settings` and `GET /api/admin/settings/{key}` never
return the hash; the single-key read answers 404 "Setting not found", as for a
missing key.

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
| 400 | Illegal status move, or a form that is locked (finalized) |
| 403 | Caller's role can't do this (employees on any `/bir` route; admin or HR on `approve`, `reject`, `finalize`, or a `PUT` to a pending or approved form; HR or accounting on the switch routes), or the feature is switched off (`bir_forms_enabled`, any route but config and the switch routes) |
| 404 | Draft not found |
| 422 | Request body failed validation, or `submit`/`approve` refused a form that fails validation, or a wrong or missing company password on the switch routes (§6) |
| 429 | Too many tries on the switch or its password: more than 5 in a minute by the same user, counted across both routes (§6) |

400 for illegal status moves matches `LeaveController::approve`, which returns
400 for "Only pending leave requests can be approved".

A role refusal by the route comes before anything else: an employee anywhere,
or admin or HR on `approve`, `reject` or `finalize`, gets 403 "Unauthorized.
Only accounting users can do this." (employees: "Unauthorized. Admin access
required.", the app-wide message) even for a draft that doesn't exist. On
`PUT` the order is 404, then 400 (locked), then 403 (role), then 422 (fields).

The switch is checked right after the employee check and before everything
else: with it off, admin, HR and accounting get the switch message on every
route but config and the switch routes, including admin or HR on `approve`, `reject` or `finalize`
and drafts that don't exist. An employee still gets the role message.

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
| `root_id` | `id` of the original (version 1) this form is a version of: `parent_id` followed back. Its own `id` on an original. |
| `label` | How the form is named on screen and in the chat: `"#64"` for an original, `"#64v2"` for its second version, `"#64v3"` for the third. Every version shares the original's number. |
| `amendments` | On a 1601-C, `{ made, limit }`: amendments made to this form's month so far (its highest version less one) and the most allowed (`BirFormDraft::AMENDMENT_LIMITS`, 5). Null on a 2316, which has no limit. |
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
| `invalid_format` | error | Text that doesn't follow its field's `format`: a TIN, RDO code, ZIP, phone number, email or MM/DD date. `PUT` refuses these already (§6); this still catches one from older data, or a badly formatted figure from payroll or company settings. |
| `fixed_value` | error | A field with a schema `fixed` value (§4, 1601-C items 13 and 13A) doesn't hold it: a different answer, or pending/blank. On a fixed blank (`"fixed": null`, item 13A) blank or pending is right and only text is wrong ("must be blank"). `PUT` can't change a fixed field, so only a draft made before it was fixed can have this; the message says to create a new draft for the period. A fixed field that is missing gets only this code, not `required`/`record_gap`. |
| `system_value` | error | A `system_filled` field (§4) doesn't hold the value the draft itself gives it: 1601-C item 2 "Amended Return?" must be No on version 1 and Yes on version 2 and up. A different value, pending or blank is wrong. `PUT` can't change it, so only an older draft can have this. A missing one gets only this code, not `required`. |
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
`options` on enums, `required_when` on conditional fields, and `format` on text
that must follow a pattern (Dev A's `FieldFormat`: `tin`, `rdo`, `zip`, `phone`,
`email`, `mmdd`). Both schemas are
served on `GET /bir/config` (§6).

A field may also carry `fixed`: the value it always has. The system fills it in
on every new draft, so it is never `pending`, never asked and never editable:
`PUT` refuses any answer to it (§6), and `validate` reports a draft that doesn't
hold it as `fixed_value` (§3). Read it with `array_key_exists`/`"fixed" in field`,
not a truthiness check, because the value can be `false` or `null`. `"fixed": null`
means always blank; the field is stored as `""`, not `null`, because `null`
always means pending (§3).

**1601-C item 13 (`has_tax_relief`) is fixed at No** (`"fixed": false`, origin
`payroll`): the supervisor's decision, since no employee's pay is tax-exempt
under a special law or tax treaty. So **item 13A (`tax_relief_details`) is fixed
blank** (`"fixed": null`, stored as `""`, origin `payroll`).

A field may instead carry `"system_filled": true`: the system fills it in from
the draft itself, so it is never `pending`, never asked and never editable, but
it isn't one fixed value. `PUT` refuses any answer to it (§6), and `validate`
reports a wrong one as `system_value` (§3). **1601-C item 2 (`is_amended`,
"Amended Return?") is system-filled**: No (`false`) on the original, Yes (`true`)
on every version made by `revise` (§6), origin `payroll` on both.

Dev D shows items 2, 13 and 13A read-only.

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
entry (§6). Fields can be edited in `draft`, `pending` and `approved` (supervisor's
rule): an approved form isn't filed yet, so a change sends it back to `draft`
(`approved_by` cleared), to be submitted and approved again. A finalized form is
locked: never edited or moved; corrections start a new version and leave the
original as filed.

**Who may approve:** accounting only, confirmed by the supervisor and enforced
since Week 7. `approve`, `reject` (return to the preparer) and `finalize` (mark
as filed) are accounting-only, and so is editing a pending or approved form; admin and HR
get 403. Admin, HR and accounting can all list, view, create, edit a draft,
submit, validate, revise, export and chat. Employees get 403 on every `/bir`
route. The preparer may approve their own form (supervisor's decision: there
is one accountant). The approver is still recorded in `approved_by`.

**Workflow, as decided:** a preparer fills in the form and submits it.
Accounting reviews the submitted form, may edit it, and approves it in the same
step. There is no send-back just to fix a figure. The approver may also be the
person who prepared the form. The approver's edits are
recorded like any other (`edited_by`, §3), and `approve` validates again, so an
edit that breaks the form blocks approval until it is fixed.

---

## 6. Endpoints

| Method | Path | Purpose | Who |
|---|---|---|---|
| GET | `/bir/config` | Feature flag, form types, status flow, both form schemas | admin, hr, accounting |
| GET | `/bir/drafts` | Paginated list, filterable by `status` and `form_type` | admin, hr, accounting |
| POST | `/bir/drafts` | Create | admin, hr, accounting |
| GET | `/bir/drafts/{id}` | One draft | admin, hr, accounting |
| PUT | `/bir/drafts/{id}` | Edit field values | admin, hr, accounting on a draft; accounting only on a pending or approved form |
| POST | `/bir/drafts/{id}/validate` | Run the checks and store `validation_errors` | admin, hr, accounting |
| POST | `/bir/drafts/{id}/submit` | draft → pending, if the draft passes validation | admin, hr, accounting |
| POST | `/bir/drafts/{id}/approve` | pending → approved, if the form passes validation | accounting only |
| POST | `/bir/drafts/{id}/reject` | pending → draft | accounting only |
| POST | `/bir/drafts/{id}/finalize` | approved → finalized | accounting only |
| POST | `/bir/drafts/{id}/revise` | New version from a finalized draft | admin, hr, accounting |
| GET | `/bir/drafts/{id}/export` | The official BIR PDF, filled in (Dev C) | admin, hr, accounting |
| GET | `/bir/drafts/{id}/employees` | A 1601-C's employee list, frozen when the draft was made | admin, hr, accounting |
| POST | `/bir/chat` | The form assistant's chat: understand a request, fill in a draft, read or change any form (Dev C) | admin, hr, accounting |
| PUT | `/bir/switch/password` | Set or change the company password for the switch | admin only |
| PUT | `/bir/switch` | Turn the BIR Form Assistant on or off, with the company password | admin only |

Employees get 403 on all of them.

### GET /bir/config

```json
{
  "enabled": false,
  "switch_password_set": false,
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
default — the feature switch, enforced since Week 8 (§1). While it is
false, config and the two switch routes are the only `/bir` routes that work;
every other one returns 403 "The BIR Form Assistant is not enabled". Only
`PUT /bir/switch` changes it. `status_flow` is served from the same
constant the controller enforces, so Dev D can drive button visibility from it
rather than hardcoding the transitions.

`switch_password_set` is true once an admin has set the company password
(`PUT /bir/switch/password`). While it is false, `PUT /bir/switch` refuses with
"No company password is set up", so the screen can ask for a password first.
Config never returns the password or its hash.

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

Editing is allowed in `draft`, `pending` and `approved` status (§5). The
approver can correct a submitted form before approving it; a pending form stays
`pending`. An approved form that is changed goes back to `draft` with
`approved_by` cleared, so it has to be submitted and approved again before it
can be filed. A finalized form returns 400 "Only draft, pending and approved
forms can be edited; this form is in finalized status". A pending or approved
form can be edited by accounting only: admin and HR get 403 "Only accounting can
edit a form that is under review or approved", whatever the body (checked before
the answers, so never a 422), and an approved form keeps its approval. Anyone
who reaches `/bir` can edit a `draft`.

A save that changes nothing (every field keeps its value, `origin` and
`edited`) writes nothing: it returns 200 with the draft as it is and message
"No changes to save", leaves the status alone (an approved form stays
approved) and adds no audit entry. Values are compared exactly, so `"1000"`
and `"1000.00"` count as a change. A real change returns "Draft updated".

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

A text field with a `format` must also follow it, for example a TIN like
`123-456-789-000`, an RDO code like `043` or `21A`, or a ZIP like `1004`. The
message says what the value should look like: `"ZIP Code" must be a 4-digit ZIP
code like 1004.` Blank text (`""`) isn't format-checked; whether a field may be
blank is the missing-fields rule's job.

A field with a schema `fixed` value (§4, 1601-C item 13) can't be answered at
all: any value, `null` and the fixed value itself included, is refused with
`"Are there payees availing of tax relief under Special Law or International Tax
Treaty?" is always No and can't be changed.` (a yes/no field's value is worded
Yes/No, and a fixed blank "blank": `"If yes, specify" is always blank and can't
be changed.` for item 13A).

A `system_filled` field (§4, 1601-C item 2) is refused the same way, any value
and `null` included: `"Amended Return?" is filled in automatically: No on the
original, Yes on an amended return.`

`null` is otherwise always accepted and clears the field. One bad answer refuses the whole
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
row and Dev C's prompts can re-ask that question. The same checks apply to a pending or approved form. A missing draft is still 404, a locked form still 400, and a
pending or approved form edited by admin or HR still 403, whatever the body.

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

No request body. Accounting only. Works like `submit`: the approver may have
edited the form since it was submitted, so it is checked again.

| Code | When |
|---|---|
| 403 | The caller is not accounting: "Unauthorized. Only accounting users can do this." Checked first, by the route. |
| 400 | The form is not in `pending` status. Nothing is validated or changed. |
| 422 | At least one `severity: error` entry. Same shape as `submit`'s 422, with "Draft has 1 error; fix it before approving". The form stays `pending` and `approved_by` stays null. |
| 200 | Warnings only, or nothing: the form moves to `approved` and `approved_by` is the caller, who may also be the preparer. |

### POST /bir/drafts/{id}/reject

```json
{ "reason": "Item 25 does not match the payroll register for May." }
```

Required, max 1000 characters. 422 if missing. Accounting only: anyone else
gets 403 "Unauthorized. Only accounting users can do this." 400 unless the form
is `pending`; 200 moves it back to `draft` with `rejection_reason` set.

### POST /bir/drafts/{id}/finalize

No request body. Marks an approved form as filed. Accounting only: anyone else
gets 403 "Unauthorized. Only accounting users can do this." 400 unless the form
is `approved`; 200 moves it to `finalized`, after which it is locked (§5).

### POST /bir/drafts/{id}/revise

Only on a finalized draft, else 400. Returns 201 with a copy in `draft` status,
`parent_id` set to the original, `version` one above the highest version of the
same form (form type, period and employee) so two drafts never share a number, `approved_by` and
`rejection_reason` cleared, `prepared_by` set to the current user. The original
is untouched. On a 1601-C the copy's item 2 "Amended Return?" (`is_amended`) is
set to Yes, filled in by the system (origin `payroll`, `system_filled` §4), since
every version after the original is an amended return; its
`label` is the original's number with the version, like `"#64v2"`.

A 1601-C can be amended 5 times per month (supervisor's rule,
`BirFormDraft::AMENDMENT_LIMITS`): once the month's highest version is 6, `revise`
returns 400 "This 1601-C has already been amended 5 times for 2026-07, the most
allowed for one month" and creates nothing. An amendment counts once, however many
boxes it changes. A 2316 has no limit. The chat (`POST /bir/chat`) offers to start
a correction only while `revise` would allow it, and starts it through this endpoint.

### GET /bir/drafts/{id}/export

Returns the official BIR form as a PDF (`Content-Type: application/pdf`), filled
in with the draft's values: two pages for a 1601-C, one for a 2316. Page 2 of the
1601-C carries only the TIN and withholding agent's name at its top. Text never runs
out of its box: a long address goes onto two lines, and text too long for its
character cells is written small across them rather than cut off. Built by Dev C
(`BirPdfService`) on the blank forms in `backend/resources/bir-templates/`.

Any status can be exported, so a draft can be printed and checked. Until the form
is `approved` or `finalized`, every page carries "DRAFT #id (status) — NOT FOR
FILING" across the top and the filename ends in `-DRAFT`. The filename is in
`Content-Disposition`, for example
`attachment; filename="BIR-2316-2026-EMP-0001-v1-DRAFT.pdf"`: form type, period,
the employee code on a 2316, and the version.

404 if the draft is missing; employees get 403 like the rest of `/bir`. The PDF
shows the draft's stored values, so a later payroll change does not alter it.

### GET /bir/drafts/{id}/employees

Each employee's part of a 1601-C, for the screen and the chat. Nothing is
recomputed: the rows are the ones the aggregation produced when the draft was
created (`_meta.employees`), saved on the draft with each employee's name and TIN
as they were then. Later payroll edits, a rename, a new TIN or a change to the
minimum wage earner list don't alter it. A revision has no snapshot of its own, so
it shows the list of the nearest version up its `parent_id` chain
(`BirFormDraft::sourceSnapshot()`). Nothing else saved with the draft is returned;
the payroll rows stay hidden.

| Code | When |
|---|---|
| 403 | An employee ("Unauthorized. Admin access required."), or the switch is off ("The BIR Form Assistant is not enabled"), as on every `/bir` route. |
| 404 | `{ "success": false, "message": "Draft not found" }` |
| 422 | A 2316: `{ "success": false, "message": "The employee list is only for a 1601-C" }` |
| 200 | The list, below. |
| 200 | A 1601-C made before the employee list existed (before 13 Oct 2026): the same keys with `employees`, `totals` and `differences` null, and the message "This form was made before the employee list existed. Make a new draft to see it." |

```json
{
  "success": true,
  "data": {
    "draft_id": 12,
    "form_type": "1601-C",
    "period": "2026-09",
    "version": 1,
    "employees": [
      {
        "employee_id": 3,
        "name": "VILLANUEVA, ANDRES",
        "tin": "123-456-789-00000",
        "category": "taxable",
        "payroll_count": 2,
        "compensation": "52000.00",
        "mwe_basic": "0.00",
        "mwe_premium": "0.00",
        "thirteenth": "0.00",
        "ee": "1300.00",
        "taxable": "50700.00",
        "exempt_250k": "0.00",
        "tax_withheld": "500.00"
      }
    ],
    "totals": {
      "compensation": "52000.00", "mwe_basic": "0.00", "mwe_premium": "0.00", "thirteenth": "0.00",
      "ee": "1300.00", "taxable": "50700.00", "exempt_250k": "0.00", "tax_withheld": "500.00"
    },
    "differences": [
      { "item": "14", "key": "total_compensation", "employees_total": "52000.00", "form_value": "53000.00", "difference": "1000.00" }
    ]
  },
  "message": "Employee list retrieved"
}
```

`employees` has one row per employee with counted payroll that month, ordered by
employee id; empty for a month with no payroll (all totals "0.00").

| Key | Meaning |
|---|---|
| `employee_id` | The employee's id |
| `name` | `"LAST, FIRST"` in capitals, as it was when the draft was made. Null for a draft made before names were saved (13 Oct 2026) or an employee deleted outright by then. |
| `tin` | The TIN as the form prints it (`123-456-789-00000`), as it was then. Null if the employee had none, or as for `name`. |
| `category` | `mwe` (minimum wage earner), `exempt_250k` (₱250,000 a year or less) or `taxable` |
| `payroll_count` | How many of the employee's payrolls that month were counted |
| `compensation` | Their part of item 14 |
| `mwe_basic` | Item 15 |
| `mwe_premium` | Item 16 |
| `thirteenth` | Item 17, the non-taxable part of 13th month and other benefits |
| `ee` | Item 19, their share of SSS, PhilHealth and Pag-IBIG |
| `taxable` | Item 22 |
| `exempt_250k` | Item 23 |
| `tax_withheld` | Item 25 |

`totals` is each money column added up, from the rows as stored. Money is
contract decimal text throughout, never a number.

`differences` compares each total with the draft's current value for its item:
14 `total_compensation`, 15 `mwe_statutory_wage`, 16 `mwe_premium_pay`,
17 `thirteenth_month_and_benefits`, 19 `statutory_contributions_ee`,
22 `total_taxable_compensation`, 23 `exempt_250k_compensation` and
25 `total_taxes_withheld`. Only items that differ are listed, in that order;
`[]` means the employees add up to the form. `difference` is the form's value less
the employees' total, so a positive one means the form shows more. A blank item is
listed with `form_value` and `difference` null. Items can differ because someone
overrode a figure, because item 20 was answered (it lowers item 22, which the
employees' rows don't include), or by ₱0.01 when rounding each row differs from
rounding the month.

### POST /bir/chat

The form assistant's chat (Dev C, `BirChatController::message`). One endpoint does
three jobs: it understands a request for a form ("the August 2026 1601-C"), it fills
in an open draft one question at a time, and it reads or changes a box on any saved
form ("change item 5 on form #12 to 049"). The language model only sorts messages
into kinds; PHP finds the data and words every reply, so filling in a draft and every
command below still work with the model switched off.

**Nothing is saved by this endpoint.** An answer comes back in `data.answer` for the
caller to save through `PUT /bir/drafts/{id}`, and a workflow step comes back in
`data.action` for the caller to run through `revise` or `submit`. So every role
rule, check and history entry of those endpoints still applies.

#### Request

```json
{ "message": "change item 5 on form #12 to 049", "draft_id": 12, "field": "employee_rdo_code", "reset": false, "actions": true }
```

| Field | Type | Meaning |
|---|---|---|
| `message` | string, at most 500 characters | What the user typed. Required without `draft_id`. With `draft_id`, leave it out (or null) to get the draft's next question. |
| `draft_id` | integer | The draft the chat is filling in, when one is open. Leave it out before a draft exists. |
| `field` | string | The key of the question just asked (`data.question.field` of the previous reply), so the message is read as the answer to it. Leave it out when no question is open; the message is then read as a question or command. |
| `reset` | boolean | `true` on the first message of a new conversation: forgets what earlier messages established, the pending question, and the changes `undo` knows about. |
| `actions` | boolean | `true` when the caller runs `data.action` (below). Without it the chat never returns an action: it points to the buttons instead ("Click "Submit for review"…", "open it and click "Start a correction""). |

What earlier messages established is remembered per user for 30 minutes, so a
short follow-up ("2026", "6D", "form #64v2", "yes") finishes the question before it.

#### Reply

Always 200 with the envelope. `data` has these keys:

| Key | Meaning |
|---|---|
| `reply` | What to show. Null only with a `revise` action, whose reply comes from the next call (below). |
| `understood` | `true` when a form request is complete: `intent` then holds everything `POST /bir/drafts` needs. |
| `intent` | Without `draft_id`: what the request has established so far, `{ form_type, tax_year, tax_month, employee_query, employee_id, period }` (`period` ready for `POST /bir/drafts`, and `employee_name` once a 2316's employee is found). Null with `draft_id`. |
| `candidates` | People who match a name, when more than one does: `[{ id, employee_id, name, status }]`. Usually `[]`. |
| `action` | Null, or a workflow step for the caller to run first (below). |
| `draft_id` | With `draft_id` only: the form the reply is about. It can differ from the one sent when the chat moved to another form (a change on a form that wasn't open, a correction it started); the caller then opens that form. |
| `question` | With `draft_id` only: the box now being asked about, or null. `{ field, item, label, type, options, format, reason, guidance, text }`; send `field` back with the user's answer. |
| `answer` | With `draft_id` only: null, or `{ field, value }` to save on form `draft_id` through `PUT /bir/drafts/{draft_id}` with `{ "fields": { field: value } }`. `value` is already in the stored format. Show `reply` once the save succeeds; if the PUT refuses it, show the PUT's message and keep the question open. |
| `done` | With `draft_id` only: `true` when there is neither a question nor an answer. |

`message` is "Request understood" or "Needs clarification" without a draft, and
"Answer read", "Question asked" or "Nothing left to ask" with one.

#### Actions

| `action` | When | What the caller does |
|---|---|---|
| `{ "type": "submit", "draft_id": 12 }` | The user said "done" (or "tapos na") on an open draft that passes validation. | `POST /bir/drafts/12/submit`, then show `reply` ("Sent form #12 for review."). If the submit is refused, show its message instead. |
| `{ "type": "revise", "draft_id": 10 }` | The user asked to change a filed form, and answered "yes" to the chat's offer to start a correction. `draft_id` is the **filed** form. `reply` is null. | `POST /bir/drafts/10/revise`, open the new version, then call the chat with `{ "draft_id": <new id> }` and no message. That reply carries the change that was asked for ("Started amendment 1, form #10v2 (#13), from …") as `answer`, saved like any other. |

The chat offers a correction only while `revise` would allow it (a 1601-C stops at
5 amendments a month), and never returns an action without `actions: true`.

#### What it understands

All read in PHP, on any form whatever its status unless noted:

- **A form request** (no `draft_id`): form type, month or year, and for a 2316 the
  employee by name, employee ID or TIN ("2316 for 920-919-920-00001 2024").
- **Reading a box:** "what is item 6 on Maria Santos's 2316 for 2026?", "what is
  the local home address on form #26?". A box can be named by its item number or by
  its name; a name that fits several boxes gets "Which one do you mean?".
- **Naming a form:** its number ("form #72", "#72"), its label ("#64v2", "64v2",
  "form #64 version 2"), or its details (form type, period, person, status such as
  "filed" or "draft", version). When several fit, the chat lists them by label and
  asks; it never picks one. A reply with the number finishes the question.
- **Versions:** "how many versions does form #10 have?", "what version is form #13?".
- **A 1601-C's employees** (from `GET /bir/drafts/{id}/employees`, frozen when the
  draft was made): "who is on form #10?" lists each employee's payrolls, compensation
  and tax withheld, the totals, and any item where they don't add up to the form;
  "how much tax was withheld from Maria Santos on the March 2026 1601-C?" (or, on an
  open 1601-C, "how much was Maria paid?") gives that employee's figures by item.
- **Changing a box:** "change item 5 on form #12 to 049", or on the open draft
  "change item 5 to 049". A form that isn't open must be named by its number.
  Applied at once as `answer`; "undo" (or "undo item 5") puts back the value from
  before, as another `answer`, unless the box was changed again since.
  - A draft can be changed by anyone who reaches `/bir`. A pending or approved form
    by accounting only: anyone else is told "…is waiting for review / approved, and
    only accounting can change it now", gets no `answer`, and on an open form is asked
    no questions. An approved form that is changed goes back to draft (§5), and the
    reply says so.
  - A filed form is never changed: the chat points to its open correction if there
    is one, or offers to start one (the button, or "yes" with `actions: true`).
  - Fixed and system-filled boxes (§4: 1601-C items 2, 13 and 13A) are never asked
    and never changed; the reply says why.
- **"done"** on an open draft: says what still blocks submitting, or returns a
  `submit` action.
- **Questions about a box** on the open draft ("what does this mean?", "where did
  item 25 come from?"): explained from the schema's help text and the draft's own
  records. Tax advice and pay calculations are declined.

#### Errors

| Code | When |
|---|---|
| 403 | An employee, or the switch is off, as on every `/bir` route. |
| 404 | `draft_id` is not a draft: `{ "success": false, "message": "Draft not found" }` |
| 422 | The body failed validation (no `message` and no `draft_id`, a message over 500 characters, a wrong type). |
| 503 | Without `draft_id` only: the language model is down while a new request needed it. `{ "success": false, "message": "… You can still pick a form and period manually." }` Everything with `draft_id`, and every command above, keeps working. |

### PUT /bir/switch/password

Sets or changes the company password that guards the switch (§1). Admin only;
works while BIR is off.

```json
{ "password": "new password", "password_confirmation": "new password", "current_password": "old password" }
```

`password` is required, at least 8 characters, and must match
`password_confirmation`. `current_password` is needed only when a password is
already set (`switch_password_set` in config); the first time it is left out.

| Code | When |
|---|---|
| 403 | The caller is not an admin. Checked first, by the route. |
| 429 | More than 5 tries in a minute, counted together with `PUT /bir/switch`. Laravel's own body, `{ "message": "Too Many Attempts." }`, with a `Retry-After` header in seconds. |
| 422 | `password` missing, shorter than 8 characters, or not confirmed: the usual Laravel `{ "message", "errors": { "password": [...] } }`. Nothing changes. |
| 422 | A password is already set and `current_password` is missing or wrong: `{ "success": false, "message": "The current company password is incorrect" }`. Nothing changes. |
| 200 | `{ "success": true, "data": { "switch_password_set": true }, "message": "Company password saved" }` |

Only the bcrypt hash is stored. The audit log gets a `BIR_SWITCH_PASSWORD` row,
"<name> set the company password for the BIR switch" (or "changed"), with no old
or new values: the password and its hash are never logged.

### PUT /bir/switch

Turns the BIR Form Assistant on or off. Admin only; works while BIR is off. This
is the only way to change `bir_forms_enabled` (§1).

```json
{ "enabled": true, "password": "the company password" }
```

Both required; `enabled` is a boolean.

| Code | When |
|---|---|
| 403 | The caller is not an admin. Checked first, by the route. |
| 429 | More than 5 tries in a minute, counted together with `PUT /bir/switch/password`, as above. |
| 422 | `enabled` or `password` missing, or `enabled` not a boolean: the usual Laravel `{ "message", "errors" }`. |
| 422 | No company password has been set: `{ "success": false, "message": "No company password is set up" }`. |
| 422 | Wrong password: `{ "success": false, "message": "The company password is incorrect" }`. Nothing changes and nothing is logged. |
| 200 | `{ "success": true, "data": { "enabled": true }, "message": "The BIR Form Assistant is now on" }` ("off" when turned off). |

A 200 is also returned when the switch was already in the requested state. Each
200 writes a `BIR_SWITCH` audit row, "<name> turned the BIR Form Assistant on"
(or "off"), with old and new values such as `{ "enabled": false }` and
`{ "enabled": true }`.

### Forgotten company password

There is no reset in the app. Someone with server access runs

```bash
php artisan bir:clear-switch-password
```

It asks "Are you sure?", then deletes the saved hash and turns
`bir_forms_enabled` off. An admin then sets a new password with
`PUT /bir/switch/password` (no `current_password` needed) and turns BIR back on.

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

**Any finalized version can be revised; a 1601-C at most 5 times a month.** Decided:
a 2316 has no limit on revisions, and a 1601-C stops at 5 amendments per month (§6, revise). Versions never repeat — revising the same filed v1 twice
gives v2 then v3, both with `parent_id` pointing at v1. Still open with the
accountant: whether "revised" means a chain, where only the latest finalized
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
