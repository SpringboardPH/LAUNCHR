<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;
use App\Models\BirFormDraft;
use App\Models\BirFormSignature;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Signing a finalized BIR form (contract: docs/bir-api-contract.md), shared by the employer's
 * route (accounting) and the employee's own 2316 route. Signing never changes the form's fields
 * or status. The signer's saved image is copied, so replacing it later never changes a form
 * already signed, and the fields are hashed so the signature records what was signed.
 */
class BirFormSigner
{
    private const DISK = 'local';

    /**
     * @param  ?callable(Builder): void  $scope  narrows which forms the caller may reach (the
     *                                           employee's own 2316s); anything else is 404
     * @return array{0: int, 1: string, 2: ?BirFormDraft} HTTP status, message, and the signed form on success
     */
    public function sign(int $id, string $role, Request $request, ?callable $scope = null): array
    {
        $user = $request->user();

        return DB::transaction(function () use ($id, $role, $request, $user, $scope) {
            $query = BirFormDraft::query();
            if ($scope) {
                $scope($query);
            }
            $draft = $query->lockForUpdate()->find($id);

            if (!$draft) {
                return [404, 'Draft not found', null];
            }
            if ($refusal = $this->refusal($draft, $role, $user)) {
                return $refusal;
            }

            $copy = $this->freezeImage($user, $draft, $role);
            try {
                $draft->signatures()->create([
                    'role' => $role,
                    'user_id' => $user->id,
                    'image_path' => $copy,
                    'signed_at' => SystemClock::now(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'fields_hash' => self::fieldsHash($draft->fields ?? []),
                ]);
            } catch (\Throwable $e) {
                Storage::disk(self::DISK)->delete($copy);
                throw $e;
            }

            if ($draft->signing_method === null) {
                $draft->update(['signing_method' => BirFormDraft::SIGN_DIGITAL]);
            }

            $draft->load('signatures.user');

            return [200, $role === BirFormSignature::EMPLOYER ? 'Form signed by the employer' : 'Form signed', $draft];
        });
    }

    /**
     * SHA-256 of the fields with every key sorted, so the same answers always give the same hash.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function fieldsHash(array $fields): string
    {
        return hash('sha256', json_encode(self::sortKeys($fields), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return ?array{0: int, 1: string, 2: null} */
    private function refusal(BirFormDraft $draft, string $role, User $user): ?array
    {
        if ($draft->status !== 'finalized') {
            return [400, 'Only a finalized form can be signed', null];
        }
        if ($draft->signing_method === BirFormDraft::SIGN_PAPER) {
            return [400, 'This form is signed by hand on paper', null];
        }
        if ($role === BirFormSignature::EMPLOYEE && !$draft->signatureBy(BirFormSignature::EMPLOYER)) {
            return [400, 'The employer has to sign this form first', null];
        }
        if ($draft->signatureBy($role)) {
            return [409, $role === BirFormSignature::EMPLOYER ? 'The employer has already signed this form' : 'You have already signed this form', null];
        }

        $saved = $user->signature()->first();
        if (!$saved || !Storage::disk(self::DISK)->exists($saved->path)) {
            return [422, 'Upload your signature first', null];
        }

        return null;
    }

    /** A copy of the signer's saved image, kept with this form's signature. */
    private function freezeImage(User $user, BirFormDraft $draft, string $role): string
    {
        $saved = $user->signature()->first();
        $extension = pathinfo($saved->path, PATHINFO_EXTENSION) ?: 'png';
        $copy = "bir-signatures/{$draft->id}/{$role}-" . Str::random(40) . ".{$extension}";

        Storage::disk(self::DISK)->copy($saved->path, $copy);

        return $copy;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => self::sortKeys($item), $value);
    }
}
