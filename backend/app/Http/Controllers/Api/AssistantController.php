<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AssistantService;
use Illuminate\Http\Request;

/**
 * LaunchAssist — read-only, employee-scoped HR chat assistant.
 * Backed by Ollama (minimax-m2.5:cloud) via AssistantService. History is ephemeral
 * (frontend-held); every turn posts the full text-only message array.
 */
class AssistantController extends Controller
{
    public function chat(Request $request, AssistantService $assistant)
    {
        $validated = $request->validate([
            'messages'           => 'required|array|min:1',
            'messages.*.role'    => 'required|string|in:user,assistant',
            'messages.*.content' => 'required|string|max:4000',
        ]);

        $reply = $assistant->reply($request->user(), $validated['messages']);

        return response()->json([
            'success' => true,
            'data'    => ['reply' => $reply],
            'message' => 'KathAssist replied.',
        ]);
    }
}
