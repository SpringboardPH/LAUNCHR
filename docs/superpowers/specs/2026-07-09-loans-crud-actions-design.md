# Loans Page — View/Edit/Cancel Actions Design Spec

**Date:** 2026-07-09
**Status:** Approved design, pending implementation plan
**Author:** Brainstormed with Claude

## Summary

Add View, Edit, and Cancel (soft-delete) actions to the existing Loans page
(`frontend/src/pages/payroll/LoansPage.jsx`, backend `LoanController`) so HR
can manage loans after creation, not just create and list them. Applies
uniformly to loans regardless of intake path (employee-approved cash
advances or HR-entered government loans).

## Decisions (locked)

| Decision | Choice |
|---|---|
| Delete semantics | Soft-delete: set `status = 'cancelled'`, then `$loan->delete()` (SoftDeletes). Audit trail preserved; `loan_payments` untouched (cascade only fires on a real DB delete, not a soft delete). |
| Edit scope | `installment_amount`, `start_cutoff`, `notes`, `status` (`active`↔`cancelled` only) always editable. `principal`/`interest_rate`/`term_count` editable only while `balance == total_payable` (no payments taken yet); locked afterward. `loan_type` and `employee_id` are immutable always. |
| View contents | Full detail: loan fields + the complete `loan_payments` ledger (date, amount, cutoff). Read-only, available to everyone (including an employee viewing their own loan), not just HR. |

## Backend

### `LoanController::update(Request $request, int $id)`

- Auth: `role:admin,hr,accounting` (matches `store()`).
- Validation:
  ```php
  $rules = [
      'installment_amount' => 'sometimes|numeric|min:1',
      'start_cutoff'       => 'sometimes|date',
      'notes'              => 'sometimes|nullable|string',
      'status'             => 'sometimes|in:active,cancelled',
  ];

  $loan = Loan::findOrFail($id);
  $unpaid = bccomp((string) $loan->balance, (string) $loan->total_payable, 2) === 0; // no payments yet

  if ($unpaid) {
      $rules['principal']     = 'sometimes|numeric|min:1';
      $rules['interest_rate'] = 'sometimes|numeric|min:0|max:1';
      $rules['term_count']    = 'sometimes|integer|min:1';
  }

  $request->validate($rules);
  ```
  If the request tries to set `principal`/`interest_rate`/`term_count` while
  `!$unpaid`, those keys are simply not in `$rules` — Laravel's `validate()`
  only checks present rules, so silently ignore any of those three keys in
  the request body when locked (don't error, just don't apply them) to keep
  the request tolerant of a stale form that still has the fields disabled
  but present in its state object.
- If any of `principal`/`interest_rate`/`term_count` were actually validated
  and are present in the request (i.e. `$unpaid` was true), recompute the
  schedule using the same branch the creation paths use:
  - `$loan->loan_type === 'cash_advance'` → `LoanService::computeSchedule($principal, $interestRate, $termCount)` for `total_payable`/`installment_amount`.
  - Government types (`sss_salary`, `sss_calamity`, `pagibig_mpl`) →
    `total_payable = round($installmentAmount * $termCount, 2)` (matches
    `store()`'s formula) — uses the *effective* `installment_amount` (the
    incoming one if provided, else the existing one).
  - Set `balance = total_payable` (safe: no payments exist yet).
- Apply all provided fields via `$loan->update([...])`, building the array
  from only the keys present in the validated request (don't overwrite
  fields the caller didn't send).
- Audit: `Loan` already has the `Auditable` trait — no manual `AuditLog::log()` call needed (matches the project convention: "Models with the Auditable trait auto-log changes — don't add manual audit calls for these").
- Response: `{success, data: $loan->fresh(), message: 'Loan updated'}`.

### `LoanController::destroy(int $id)`

- Auth: `role:admin,hr,accounting`.
- `$loan = Loan::findOrFail($id); $loan->update(['status' => 'cancelled']); $loan->delete();`
- Response: `{success: true, data: null, message: 'Loan cancelled'}`.
- No restriction on which status it can be cancelled from (simple), but the
  frontend only surfaces the Cancel button when `status === 'active'`.

### Fix: `LoanService::reverseForPayroll()` — soft-delete blind spot

`Loan::find($payment->loan_id)` respects the `SoftDeletes` global scope, so
once a loan can be soft-deleted, reversing a payroll that charged a
since-cancelled loan would silently skip restoring its balance (the `if
($loan)` guard would see `null` and only delete the `loan_payments` row).
Fix: change to `Loan::withTrashed()->find($payment->loan_id)` so reversal
always finds and correctly restores the loan regardless of its current
soft-delete state.

### Routes (`backend/routes/api.php`)

Add to the existing `loans` prefix group:
```php
Route::put('/{id}', [LoanController::class, 'update'])->middleware('role:admin,hr,accounting');
Route::delete('/{id}', [LoanController::class, 'destroy'])->middleware('role:admin,hr,accounting');
```

## Frontend

### `frontend/src/api/queries.js`

Add to the existing Loans section:
```js
export const updateLoan = (id, data) => api.put(`/loans/${id}`, data).then(r => r.data)
export const deleteLoan = (id) => api.delete(`/loans/${id}`).then(r => r.data)
```

### `frontend/src/pages/payroll/LoansPage.jsx`

- New **Actions** column, always rendered for every user. It always
  contains the View icon; the Edit and Cancel icons are added to it only
  when `canManage` is true. An employee viewing their own loans therefore
  sees the same column with just the View icon.
- **View** (eye icon, all users): opens a read-only `<Modal>` showing
  employee name, `loan_type` (via `formatLoanType`), principal, interest
  rate, term, installment amount, total payable, balance, status,
  start_cutoff, notes, approver — plus a `payments` table (reusing the
  `payments` relation already eager-loaded by `LoanController::show()`):
  cutoff range and amount per row, oldest first. Fetches via `getLoan(id)`
  (already exists) on modal open (`enabled: !!viewLoanId`).
- **Edit** (pencil icon, `canManage` only, any status): opens a form modal
  pre-filled from the loan's current values. If `balance !== total_payable`
  (payments exist), the Principal/Interest Rate/Term inputs render
  `disabled` with a small note ("Locked — payments already made against
  this loan"). Submits via `updateLoan(id, data)`, invalidates
  `loanKeys.all` on success.
- **Cancel** (trash/x icon, `canManage` only, shown only when
  `status === 'active'`): `ConfirmModal` ("Cancel this loan? Future payroll
  runs will stop deducting it.") → `deleteLoan(id)` → invalidate
  `loanKeys.all`.

## Testing

One Pest feature test extending `backend/tests/Feature/LoanTest.php`:
- `update()` recomputes `total_payable`/`balance` when `principal` changes
  on an unpaid loan; rejects (or ignores) the same field once a payment
  exists.
- `destroy()` sets `status = 'cancelled'` and soft-deletes; a subsequent
  `chargeForPayroll()` call no longer selects it.
- `reverseForPayroll()` correctly restores balance for a soft-deleted
  (cancelled) loan via `withTrashed()`.

## Out of Scope

- Restoring a cancelled/soft-deleted loan through the UI (can be done via
  `withTrashed()` + a manual DB update later if ever needed — no UI for it
  now).
- Bulk edit/cancel.
- Editing `loan_type` or reassigning `employee_id`.

## Touch List

| File | Change |
|---|---|
| `backend/app/Http/Controllers/Api/LoanController.php` | add `update()`, `destroy()` |
| `backend/app/Services/LoanService.php` | fix `reverseForPayroll()` to use `withTrashed()` |
| `backend/routes/api.php` | add `PUT`/`DELETE /api/loans/{id}` |
| `backend/tests/Feature/LoanTest.php` | 3 new test methods |
| `frontend/src/api/queries.js` | `updateLoan`, `deleteLoan` |
| `frontend/src/pages/payroll/LoansPage.jsx` | Actions column: View/Edit/Cancel |
