<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Support\Collection;

/**
 * How the chat names forms, in one place: "Maria Santos's 2316 for 2026", "the March 2026
 * 1601-C", and each form's label.
 *
 * A form's label is its original's number, then "v" and the version from the second
 * version on: the original #64 is "#64", its first correction "#64v2", the next "#64v3".
 * So every version of one form shares a number, and the label says which version it is.
 * The original is found by following parent_id back (revise sets it). The workflow's own
 * label (BirFormDraft::label(), Dev B) is used once it exists, so the screens and the chat
 * always agree; until then the same rule is worked out here.
 *
 * While the screens still show the record number ("Draft #72"), a label that differs from
 * it is followed by that number: "#64v2 (#72)". The chat understands both.
 */
final class BirFormNames
{
    /** How each status is said to the user. */
    public const STATUS_WORDS = [
        'draft' => 'a draft',
        'pending' => 'waiting for review',
        'approved' => 'approved',
        'finalized' => 'filed',
    ];

    /** At most this many forms are listed when several fit. */
    private const LISTED = 8;

    public const SEVERAL = 'More than one form fits. Which one do you mean?';

    /** The number of the original this form is a version of (itself for an original). */
    public static function rootId(BirFormDraft $draft): int
    {
        if (method_exists($draft, 'rootId')) {
            return $draft->rootId();
        }
        $seen = [];
        $form = $draft;
        while ($form->parent_id !== null && !isset($seen[$form->id]) && ($parent = BirFormDraft::find($form->parent_id)) !== null) {
            $seen[$form->id] = true;
            $form = $parent;
        }

        return $form->id;
    }

    /** "#64" for an original, "#64v2" for its second version. */
    public static function label(BirFormDraft $draft): string
    {
        if (method_exists($draft, 'label')) {
            return $draft->label();
        }

        return '#' . self::rootId($draft) . ($draft->version > 1 ? "v{$draft->version}" : '');
    }

    /** The label, and the record number too while the two differ: "#64v2 (#72)". */
    public static function name(BirFormDraft $draft): string
    {
        $label = self::label($draft);

        return $label === "#{$draft->id}" ? $label : "{$label} (#{$draft->id})";
    }

    /** "original"; "amendment 1" for a 1601-C's second version, "version 2" for a 2316's. */
    public static function versionWord(BirFormDraft $draft): string
    {
        return match (true) {
            $draft->version <= 1 => 'original',
            self::isAmendment($draft) => 'amendment ' . ($draft->version - 1),
            default => "version {$draft->version}",
        };
    }

    /** "More than one form fits. Which one? - form #64v2 (#72): …" */
    public static function listing(Collection $forms, string $lead = self::SEVERAL): string
    {
        $lines = $forms->take(self::LISTED)->map(fn (BirFormDraft $d) => '- ' . self::line($d))->implode("\n");
        $more = $forms->count() > self::LISTED
            ? "\n…and " . ($forms->count() - self::LISTED) . ' more. Add a detail such as the period, the person, or "filed" or "draft".'
            : '';

        return "{$lead}\n{$lines}{$more}\nSay its number, for example \"form " . self::label($forms->first()) . '".';
    }

    /** "form #64v2 (#72): the March 2026 1601-C, amendment 1, a draft". */
    public static function line(BirFormDraft $draft): string
    {
        return 'form ' . self::name($draft) . ': ' . self::describe($draft) . ', ' . self::versionWord($draft) . ', ' . self::statusWord($draft);
    }

    /** "form #64v2 (#72), amendment 1, a draft". */
    public static function versionNote(BirFormDraft $draft): string
    {
        return 'form ' . self::name($draft) . ', ' . self::versionWord($draft) . ', ' . self::statusWord($draft);
    }

    /** "amendment 2" on a 1601-C, "version 2" on a 2316. */
    public static function correctionName(BirFormDraft $draft): string
    {
        return self::versionWord($draft);
    }

    /** A corrected 1601-C is an amendment, counted against its month's limit. */
    public static function isAmendment(BirFormDraft $draft): bool
    {
        return $draft->form_type === Form1601CSchema::FORM_TYPE;
    }

    /** "Maria Santos's 2316 for 2026" / "the September 2026 1601-C". */
    public static function describe(BirFormDraft $draft): string
    {
        if ($draft->form_type === Form2316Schema::FORM_TYPE) {
            $name = trim(($draft->employee?->first_name ?? '') . ' ' . ($draft->employee?->last_name ?? ''));

            return ($name !== '' ? self::possessive($name) : 'the') . " 2316 for {$draft->period}";
        }

        return 'the ' . self::monthName($draft->period) . ' 1601-C';
    }

    /** "filed", "waiting for review", "a draft", or "returned for changes" for a draft the reviewer sent back. */
    public static function statusWord(BirFormDraft $draft): string
    {
        return $draft->status === 'draft' && $draft->rejection_reason !== null
            ? 'returned for changes'
            : (self::STATUS_WORDS[$draft->status] ?? $draft->status);
    }

    /** "2026-09" → "September 2026". */
    public static function monthName(string $period): string
    {
        return preg_match('/^(\d{4})-(\d{2})$/', $period, $m) ? date('F', mktime(0, 0, 0, (int) $m[2], 1)) . " {$m[1]}" : $period;
    }

    /** What a reference asks for, in words: "a 2316 for Maria Santos for 2026", "a 1601-C for September 2026". */
    public static function wanted(array $ref): string
    {
        $who = $ref['employee'] !== null ? ' for ' . trim($ref['employee']->first_name . ' ' . $ref['employee']->last_name) : '';
        $when = match (true) {
            $ref['year'] !== null && $ref['month'] !== null => ' for ' . self::monthName(sprintf('%04d-%02d', $ref['year'], $ref['month'])),
            $ref['month'] !== null => ' for ' . date('F', mktime(0, 0, 0, $ref['month'], 1)),
            $ref['year'] !== null => " for {$ref['year']}",
            default => '',
        };

        return "a {$ref['form_type']}{$who}{$when}";
    }

    private static function possessive(string $name): string
    {
        return str_ends_with(mb_strtolower($name), 's') ? "{$name}'" : "{$name}'s";
    }
}
