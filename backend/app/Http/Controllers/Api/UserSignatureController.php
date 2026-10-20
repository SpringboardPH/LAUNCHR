<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The logged-in user's own saved signature (contract: docs/bir-api-contract.md). Any role.
 * Always the caller's own: there is no user id in these routes, so nobody can reach another
 * person's image. The file is on the private `local` disk and its path never leaves the server.
 */
class UserSignatureController extends Controller
{
    private const DISK = 'local';

    public function show(Request $request)
    {
        $signature = $this->signatureOf($request);

        return response()->json([
            'success' => true,
            'data' => [
                'has_signature' => $signature !== null,
                'uploaded_at' => $signature?->updated_at?->toIso8601String(),
            ],
            'message' => $signature ? 'Signature found' : 'No signature uploaded',
        ]);
    }

    /** The image bytes, for the owner's own preview only. */
    public function image(Request $request)
    {
        $signature = $this->signatureOf($request);

        if (!$signature || !Storage::disk(self::DISK)->exists($signature->path)) {
            return response()->json(['success' => false, 'message' => 'No signature uploaded'], 404);
        }

        return response(Storage::disk(self::DISK)->get($signature->path), 200, [
            'Content-Type' => $signature->mime,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'signature' => 'required|file|mimes:png,jpg,jpeg|max:1024|dimensions:max_width=2000,max_height=1000',
        ]);

        $user = $request->user();
        $file = $request->file('signature');
        $mime = $file->getMimeType() === 'image/png' ? 'image/png' : 'image/jpeg';
        $extension = $mime === 'image/png' ? 'png' : 'jpg';

        $path = Storage::disk(self::DISK)->putFileAs(
            "signatures/{$user->id}",
            $file,
            Str::random(40) . ".{$extension}"
        );

        $old = $this->signatureOf($request);
        $oldPath = $old?->path;

        if ($old) {
            $old->update(['path' => $path, 'mime' => $mime]);
            $old->touch();
        } else {
            $user->signature()->create(['path' => $path, 'mime' => $mime]);
        }

        if ($oldPath && $oldPath !== $path) {
            Storage::disk(self::DISK)->delete($oldPath);
        }

        $signature = $user->signature()->first();

        return response()->json([
            'success' => true,
            'data' => [
                'has_signature' => true,
                'uploaded_at' => $signature->updated_at?->toIso8601String(),
            ],
            'message' => $old ? 'Signature replaced' : 'Signature uploaded',
        ], $old ? 200 : 201);
    }

    /** Always a fresh query: a relation already loaded on the user could be out of date. */
    private function signatureOf(Request $request): ?UserSignature
    {
        return $request->user()->signature()->first();
    }

    public function destroy(Request $request)
    {
        $signature = $this->signatureOf($request);

        if ($signature) {
            Storage::disk(self::DISK)->delete($signature->path);
            $signature->delete();
        }

        return response()->json([
            'success' => true,
            'data' => ['has_signature' => false, 'uploaded_at' => null],
            'message' => 'Signature removed',
        ]);
    }
}
