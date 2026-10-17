<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;

/**
 * Sending a form for review from the chat (supervisor's rule): only when the user asks for it
 * in so many words, in any wording that says it directly — "submit it for review", "send this
 * to accounting for approval", "pakisubmit", "i-submit mo na", "submit form #11". Anything
 * else leaves the form a draft. "done" and "tapos na" don't submit: they only get a reply
 * saying the form stays a draft and how to submit, so they are never saved as an answer.
 *
 * The submit itself is the workflow's (POST /bir/drafts/{id}/submit), run by the caller from
 * the action this returns, so its checks and roles apply. Replies use BirChatCommands::result().
 */
class BirChatSubmit
{
    /** Words that ask to submit outright: "submit", "i-submit", "pakisubmit", "resubmit". */
    private const SUBMIT = '/\b(?:re-?|paki-?|pa-?|i-?)?submit(?!ted|ssion)/i';

    /** Sending it on, when the message also says where: for review or approval, or to accounting. */
    private const SEND = '/\b(?:send|forward|pass|turn in|ipasa|pakipasa|ipadala|pakipadala|i-?forward)\b.*\b(?:for (?:review|approval|checking)|to (?:accounting|the reviewer|review)|i-?review|ma-?review|aprubahan|approval)\b/i';

    /** A question about submitting, or a "not yet": never a request to submit. */
    private const NOT_A_REQUEST = '/^\s*(?:how|paano|pano|what|ano|when|kailan|why|bakit|where|saan|which|who|is|are|was|were|has|have|did|does|can i|should i|do i|show|list)\b|\b(?:don\'?t|do not|not yet|hindi pa|huwag|wag|hold off|before)\b/i';

    /** Being finished with the boxes, which is not a request to submit. */
    private const DONE = '/^\s*(?:done|i\'?m done|all done|tapos na?|finished|that\'?s all|ok(?:ay)? done)\s*[.!]*\s*$/i';

    public function __construct(
        private readonly BirFormFinder $finder,
        private readonly BirChatLookup $lookup,
        private readonly BirDraftValidator $validator,
    ) {}

    /** True when the message asks, directly, for a form to be sent for review. */
    public static function asksToSubmit(string $message): bool
    {
        if (preg_match(self::NOT_A_REQUEST, $message) && !preg_match('/\b(?:can|could|would|will) you\b|\bpaki/i', $message)) {
            return false;
        }

        return preg_match(self::SUBMIT, $message) === 1 || preg_match(self::SEND, $message) === 1;
    }

    /** "done" on its own: the form stays a draft. Null when no draft is open (nothing to say). */
    public function done(string $message, ?BirFormDraft $current): ?array
    {
        if (!preg_match(self::DONE, $message) || $current === null) {
            return null;
        }
        $how = 'When it is ready, say "submit it for review" or click "Submit for review".';

        return $current->status === 'draft'
            ? BirChatCommands::result('Okay. Form ' . BirFormNames::name($current) . " stays a draft until you send it for review. {$how}", null, null, stay: true)
            : BirChatCommands::result('Okay. Form ' . BirFormNames::name($current) . ' is ' . BirFormNames::statusWord($current) . '.', null, null, stay: true);
    }

    /** The form named in the message (or the open one) goes for review, through the workflow's submit. */
    public function submit(string $message, ?BirFormDraft $current, BirConversationState $state, bool $canAct): array
    {
        // "for review" and "to accounting" say where it goes, not whose form it is ("2316 for Maria").
        $ref = $this->finder->reference(preg_replace('/\b(?:for (?:review|approval|checking)|to (?:accounting|the reviewer|review))\b/i', ' ', $message));
        $form = BirFormFinder::specific($ref) || $ref['form_type'] !== null ? $this->lookup->target($ref, $current, $message, $state) : $current;
        if (is_array($form)) {
            return $form;
        }
        if ($form === null) {
            return BirChatCommands::result('Which form should I send for review? Open it, or give its number, for example "submit form #11 for review".');
        }

        $name = 'Form ' . BirFormNames::name($form) . ' (' . BirFormNames::describe($form) . ')';
        if ($form->status !== 'draft') {
            return BirChatCommands::result("{$name} is already " . BirFormNames::statusWord($form) . ', so there is nothing to submit.', null, null, stay: true);
        }

        $errors = array_values(array_filter($this->validator->validate($form), fn (array $e) => $e['severity'] === 'error'));
        if ($errors !== []) {
            $count = count($errors);
            $list = implode("\n", array_map(fn (array $e) => "- {$e['message']}", array_slice($errors, 0, 5)));

            return BirChatCommands::result("{$name} can't be sent for review yet: {$count} " . ($count === 1 ? 'issue needs' : 'issues need') . " fixing first. It stays a draft.\n{$list}", null, null, stay: true);
        }

        if (!$canAct) {
            return BirChatCommands::result("Nothing is blocking {$name}. Click \"Submit for review\" above the preview to send it for review.", null, null, stay: true);
        }

        $note = '';
        if ($form->version > 1) {
            $limit = BirFormFinder::amendmentLimit($form->form_type);
            $note = $limit !== null
                ? ' This is amendment ' . ($form->version - 1) . ': ' . BirFormNames::monthName($form->period) . ' has used ' . ($form->version - 1) . " of its {$limit} amendments."
                : " This is correction version {$form->version}.";
        }

        return BirChatCommands::result('Sent form ' . BirFormNames::name($form) . " for review.{$note}", null, null, ['type' => 'submit', 'draft_id' => $form->id]);
    }
}
