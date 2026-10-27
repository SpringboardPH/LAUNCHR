<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BIR\BirTokenUsage;

/** GET /api/bir/token-usage: the chat's AI tokens used and left until the budget resets (BirTokenUsage). */
class BirTokenUsageController extends Controller
{
    public function show()
    {
        return response()->json(['success' => true, 'data' => BirTokenUsage::status(), 'message' => 'Token usage']);
    }
}
