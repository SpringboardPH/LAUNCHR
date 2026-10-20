<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BirFormDraftResource;
use App\Mail\BirFormToSignMail;
use App\Models\BirFormDraft;
use App\Models\BirFormSignature;
use App\Services\BIR\BirFormSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The employer's side of signing a finalized form (contract: docs/bir-api-contract.md):
 * accounting-only, by route. Signing is optional and never blocks anything: accounting either
 * e-signs (sign) or marks the form for a wet signature on the printout (wetSignature), or leaves
 * it unsigned. Neither changes the form's fields or status.
 */
class BirSigningController extends Controller
{
    public function sign(BirFormSigner $signer, Request $request, int $id)
    {
        [$status, $message, $draft] = $signer->sign($id, BirFormSignature::EMPLOYER, $request);

        if ($draft === null) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        // Sent after the transaction, so never for a signature that didn't save.
        $this->tellTheEmployee($draft);

        return $this->formResponse($draft, $message);
    }

    /**
     * A 2316 is the employee's to sign next: email them, at the address payslips use. Not when
     * they have no email or no login, since they couldn't sign anyway.
     */
    private function tellTheEmployee(BirFormDraft $draft): void
    {
        $employee = $draft->employee;
        if ($draft->form_type !== '2316' || !$employee || empty($employee->email) || !$employee->user_id) {
            return;
        }

        Mail::to($employee->email)->queue(new BirFormToSignMail($draft));
    }

    /** Records that the form is (or was) signed by hand. Nothing is uploaded or stored. */
    public function wetSignature(int $id)
    {
        return DB::transaction(function () use ($id) {
            $draft = BirFormDraft::lockForUpdate()->find($id);
            if (!$draft) {
                return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
            }
            if ($draft->status !== 'finalized') {
                return response()->json(['success' => false, 'message' => 'Only a finalized form can be signed'], 400);
            }
            if ($draft->signatures()->exists()) {
                return response()->json(['success' => false, 'message' => 'This form has already been signed digitally'], 409);
            }

            // Already marked: saving again is a no-op, not an error.
            if ($draft->signing_method !== BirFormDraft::SIGN_PAPER) {
                $draft->update(['signing_method' => BirFormDraft::SIGN_PAPER]);
            }

            return $this->formResponse($draft, 'Form marked for a wet signature');
        });
    }

    private function formResponse(BirFormDraft $draft, string $message)
    {
        $draft->load('preparer', 'approver', 'employee', 'signatures.user');

        return response()->json(['success' => true, 'data' => new BirFormDraftResource($draft), 'message' => $message]);
    }
}
