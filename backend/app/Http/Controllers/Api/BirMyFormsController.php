<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BirFormDraftResource;
use App\Models\BirFormDraft;
use App\Models\BirFormSignature;
use App\Services\BIR\BirFormSigner;
use App\Services\BIR\BirPdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * An employee's own 2316s to sign (contract: docs/bir-api-contract.md). Any role, but only the
 * caller's own forms: a 2316 whose employee record has the caller's user_id. Anyone else's form
 * is simply not found (404, never 403), so ids can't be probed. The list and the PDF show only
 * finalized forms the employer has signed; signing looks at all of the caller's 2316s, so it can
 * say why a form can't be signed yet.
 */
class BirMyFormsController extends Controller
{
    public function index(Request $request)
    {
        $forms = $this->ready($request)
            ->with('signatures.user')
            ->orderBy('period', 'desc')
            ->orderBy('version', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $forms->map(fn (BirFormDraft $form) => $this->summary($form))->values(),
            'message' => 'Your BIR forms to sign',
        ]);
    }

    /** The same PDF accounting exports (BirPdfService), for the employee to review before signing. */
    public function pdf(BirPdfService $pdf, Request $request, int $id)
    {
        $draft = $this->ready($request)->with('employee')->find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Form not found'], 404);
        }

        $name = sprintf('BIR-2316-%s%s-v%d.pdf', $draft->period, $draft->employee ? '-' . $draft->employee->employee_id : '', $draft->version);

        return response($pdf->render($draft), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }

    public function sign(BirFormSigner $signer, Request $request, int $id)
    {
        $userId = $request->user()->id;
        [$status, $message, $draft] = $signer->sign(
            $id,
            BirFormSignature::EMPLOYEE,
            $request,
            fn (Builder $query) => $this->ownedBy($query, $userId)
        );

        if ($draft === null) {
            $message = $status === 404 ? 'Form not found' : $message;

            return response()->json(['success' => false, 'message' => $message], $status);
        }

        return response()->json(['success' => true, 'data' => $this->summary($draft), 'message' => $message]);
    }

    /** The caller's finalized 2316s that the employer has e-signed. */
    private function ready(Request $request): Builder
    {
        return $this->ownedBy(BirFormDraft::query(), $request->user()->id)
            ->where('status', 'finalized')
            ->where('signing_method', BirFormDraft::SIGN_DIGITAL)
            ->whereHas('signatures', fn (Builder $query) => $query->where('role', BirFormSignature::EMPLOYER));
    }

    private function ownedBy(Builder $query, int $userId): Builder
    {
        return $query->where('form_type', '2316')
            ->whereHas('employee', fn (Builder $employee) => $employee->where('user_id', $userId));
    }

    /** @return array<string, mixed> */
    private function summary(BirFormDraft $form): array
    {
        $signing = (new BirFormDraftResource($form))->resolve()['signing'];

        return [
            'id' => $form->id,
            'label' => $form->label(),
            'tax_year' => $form->period,
            'signing' => $signing,
            'needs_my_signature' => $signing['employee'] === null,
        ];
    }
}
