<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Models\User;

/**
 * "undo" in the chat: takes back a change the chat made, newest first, or the last one to an
 * item ("undo item 6"). Every change and answer the chat hands over for saving is remembered
 * with the box as it was (BirConversationState), so undo puts back exactly that: the earlier
 * value, the calculated figure, or an empty box.
 *
 * A change is only undone while the box still holds what the chat saved, so a later edit on
 * the form itself is never overwritten. Like every chat change, the undo comes back as an
 * answer for the caller to save through PUT, with the same checks and history.
 */
class BirChatUndo
{
    public function __construct(private readonly BirConversationService $conversation) {}

    /** Remember a change or answer about to be saved, so "undo" can take it back. */
    public function remember(BirConversationState $state, BirFormDraft $draft, array $answer): void
    {
        $state->rememberChange([
            'draft_id' => $draft->id,
            'field' => $answer['field'],
            'item' => $this->conversation->field($draft->form_type, $answer['field'])['item'],
            'before' => ($draft->fields ?? [])[$answer['field']] ?? [],
            'after' => $answer['value'],
        ]);
    }

    /** @return array{reply: ?string, draft: ?BirFormDraft, answer: ?array, action: ?array, candidates: array, stay: bool} */
    public function undo(?string $item, ?BirFormDraft $current, BirConversationState $state, User $user): array
    {
        $changes = $state->changes();
        $index = null;
        for ($i = count($changes) - 1; $i >= 0; $i--) {
            if ($item === null || strtoupper((string) $changes[$i]['item']) === $item) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return BirChatCommands::result($item === null
                ? 'There is nothing to undo. I can undo changes made here in the chat; changes made on the form itself have "Restore calculated" there.'
                : "I haven't changed item {$item} in this conversation, so there is nothing to undo.", stay: true);
        }

        $change = $changes[$index];
        $state->dropChange($index);
        $draft = BirFormDraft::with('employee')->find($change['draft_id']);
        if ($draft === null) {
            return BirChatCommands::result('That form no longer exists, so there is nothing to undo.', stay: true);
        }

        $field = $this->conversation->field($draft->form_type, $change['field']);
        $name = BirConversationService::boxName($field);
        if (!in_array($draft->status, BirFormDraft::EDITABLE_STATUSES, true)
            || ($draft->status === 'pending' && !in_array($user->role, BirFormDraft::PENDING_EDITOR_ROLES, true))) {
            return BirChatCommands::result('Form ' . BirFormNames::name($draft) . ' is ' . BirFormNames::statusWord($draft) . " now, so the change to {$name} can't be undone here.", stay: true);
        }

        $now = ($draft->fields ?? [])[$change['field']]['value'] ?? null;
        if (!BirConversationService::same($now, $change['after'])) {
            return BirChatCommands::result("{$name} has been changed again since (it is now " . BirConversationService::shown($now, $field) . '), so I left it as it is.', stay: true);
        }

        $before = $change['before'];
        $was = $before['value'] ?? null;
        $empty = ($before['origin'] ?? 'pending') === 'pending' || $was === null;
        $restore = match (true) {
            $empty => null,
            empty($before['edited']) && in_array($before['origin'], ['payroll', 'settings'], true) => null,
            default => $was,
        };
        $back = match (true) {
            $empty => 'It is empty again.',
            $restore === null => 'It is back to the calculated figure, ' . BirConversationService::shown($was, $field) . '.',
            default => BirConversationService::sentence('It is back to ' . BirConversationService::shown($was, $field)),
        };
        $where = $current === null || $current->id !== $draft->id ? ' on form ' . BirFormNames::name($draft) : '';

        return BirChatCommands::result("Undid the change to {$name}{$where}. {$back}", $draft, ['field' => $change['field'], 'value' => $restore]);
    }
}
