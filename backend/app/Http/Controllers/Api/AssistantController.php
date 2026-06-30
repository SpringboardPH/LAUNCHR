<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\SystemClock;
use Illuminate\Http\Request;

/**
 * Placeholder chat assistant ("LaunchAssist").
 *
 * MVP stub: returns canned, keyword-based replies — NO external AI call yet.
 * The real Anthropic + employee-scoped tool-use engine is specified in the
 * vault plan (Plans/AI Assistant.md) and will replace replyTo() later.
 */
class AssistantController extends Controller
{
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'messages'             => 'required|array|min:1',
            'messages.*.role'      => 'required|string|in:user,assistant',
            'messages.*.content'   => 'required|string|max:4000',
        ]);

        $user = $request->user();
        $lastUser = collect($validated['messages'])
            ->last(fn ($m) => $m['role'] === 'user');

        $reply = $this->replyTo($lastUser['content'] ?? '', $user?->name);

        return response()->json([
            'success' => true,
            'data'    => [
                'reply'       => $reply,
                'placeholder' => true,
            ],
            'message' => 'LaunchAssist (placeholder) replied.',
        ]);
    }

    /**
     * Deterministic, offline placeholder responses keyed off simple keywords.
     */
    private function replyTo(string $text, ?string $name): string
    {
        $t = strtolower($text);
        $who = $name ? explode(' ', $name)[0] : 'there';

        // Whole-word match so e.g. "late" does not fire on "latest".
        $match = function (array $words) use ($t) {
            foreach ($words as $w) {
                if (preg_match('/\b' . preg_quote($w, '/') . '\b/', $t)) {
                    return true;
                }
            }
            return false;
        };

        if ($match(['leave', 'vacation', 'pto', 'time off'])) {
            return "I'll be able to look up your leave balances and requests once I'm fully connected. "
                . "For now this is a placeholder — check the Leaves section of your dashboard.";
        }
        if ($match(['attendance', 'clock', 'late', 'absent', 'dtr'])) {
            return "Attendance lookups (clock-in/out, late days, summaries) are coming soon. "
                . "This is a placeholder reply — see the Attendance page in the meantime.";
        }
        if ($match(['pay', 'salary', 'payslip', 'payroll'])) {
            return "Payslip and payroll questions will be answered here once the assistant is live. "
                . "Placeholder for now — your payslips live under Payroll.";
        }
        if ($match(['schedule', 'shift', 'roster'])) {
            return "I'll show your work schedule and shifts here soon. This is a placeholder response.";
        }
        if ($match(['hello', 'hi', 'hey', 'help', 'what can you'])) {
            return "Hi {$who}! I'm LaunchAssist, your HR assistant (currently a placeholder). "
                . "Soon I'll answer questions about your leave, attendance, payslips, and schedule. "
                . "Today is " . SystemClock::today()->format('M j, Y') . ".";
        }

        return "Thanks {$who} — I received: \"" . trim($text) . "\". "
            . "I'm a placeholder for now and will give real, data-aware answers once connected.";
    }
}
