<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use InvalidArgumentException;

/**
 * Drives the conversation once a draft exists: what still needs asking (Week 4),
 * asking for it and saving the answers (Week 5), explaining figures (Week 6).
 *
 * This class knows about drafts and the database. The decision about what is
 * missing lives in BirMissingFields, which knows about neither, so it can be
 * tested on plain arrays and keeps working with the language model switched off.
 */
class BirConversationService
{
    /**
     * Fields this draft still needs from a person, in the order to ask them.
     * Run again after every answer: an answer can make new fields required.
     *
     * @return array<int, array<string, mixed>> see BirMissingFields::detect()
     */
    public function missingFields(BirFormDraft $draft): array
    {
        return BirMissingFields::detect(
            $this->schemaFor($draft->form_type),
            $draft->fields ?? [],
            $this->context($draft),
        );
    }

    /**
     * Facts about a draft that its fields can't hold but its conditions depend on.
     * Keys are BirMissingFields::CONTEXT_FACTS.
     *
     * hired_in_year: the employee started during the tax year, so they may have
     * worked somewhere else first. January 1 counts as a full year. An unknown
     * hire date counts as true — the cost of that is one yes/no question, while
     * the cost of guessing false is a 2316 missing a previous employer's income.
     *
     * @return array<string, bool>
     */
    public function context(BirFormDraft $draft): array
    {
        if ($draft->form_type !== Form2316Schema::FORM_TYPE) {
            return [];
        }

        $hired = $draft->employee?->hire_date;
        $year = (int) $draft->period;

        return [
            'hired_in_year' => $hired === null
                || ($hired->year === $year && $hired->format('m-d') !== '01-01'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function schemaFor(string $formType): array
    {
        return match ($formType) {
            Form1601CSchema::FORM_TYPE => Form1601CSchema::fields(),
            Form2316Schema::FORM_TYPE => Form2316Schema::fields(),
            default => throw new InvalidArgumentException("Unknown BIR form type: {$formType}"),
        };
    }
}
