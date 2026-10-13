<?php

namespace Tests\Unit\Bir;

use App\Services\BIR\BirBoxMatcher;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use PHPUnit\Framework\TestCase;

/**
 * Finding a box by its name: one box when the words pick it out, a question when boxes
 * share a name, nothing when the words name no box.
 */
class BirBoxMatcherTest extends TestCase
{
    /** @return array<int, string> "item:key" of each box matched */
    private function find(string $schema, string $question, array $skip = ['maria', 'santos']): array
    {
        $result = BirBoxMatcher::match($schema::fields(), $question, $skip);

        return array_map(fn (array $f) => "{$f['item']}:{$f['key']}", $result['fields']);
    }

    public function test_the_words_pick_out_one_box(): void
    {
        $this->assertSame(['12:present_employer_tin'], $this->find(Form2316Schema::class, 'what is the company TIN?'));
        $this->assertSame(['16:previous_employer_tin'], $this->find(Form2316Schema::class, "previous employer's tin"));
        $this->assertSame(['3:employee_tin'], $this->find(Form2316Schema::class, "what's her TIN"));
        $this->assertSame(['7:employee_birthdate'], $this->find(Form2316Schema::class, "maria's birthday"));
        $this->assertSame(['6B:employee_home_address'], $this->find(Form2316Schema::class, 'home address'));
        $this->assertSame(['25A:taxes_withheld_present'], $this->find(Form2316Schema::class, 'tax withheld by the company'));
        $this->assertSame(['24:tax_due'], $this->find(Form2316Schema::class, 'magkano ang tax due'));
        $this->assertSame(['4:sheets_attached'], $this->find(Form1601CSchema::class, 'number of sheets attached'));
        $this->assertSame(['35:total_penalties'], $this->find(Form1601CSchema::class, 'penalties'));
    }

    public function test_every_part_of_one_item_comes_back_together(): void
    {
        $this->assertSame(
            ['4:employee_last_name', '4:employee_first_name', '4:employee_middle_name'],
            $this->find(Form2316Schema::class, "the employee's name"),
        );
    }

    public function test_boxes_with_the_same_name_are_asked_about_not_guessed(): void
    {
        $result = BirBoxMatcher::match(Form2316Schema::fields(), "what is the TIN on maria santos's 2316 for 2026", ['maria', 'santos']);

        $this->assertTrue($result['ambiguous']);
        $this->assertSame(['3', '12', '16'], array_column($result['fields'], 'item'));
        $this->assertStringStartsWith("Which one do you mean? On the 2316 that could be:\n- item 3: the employee's Taxpayer Identification Number", BirBoxMatcher::whichOne('2316', $result['fields']));
    }

    public function test_words_that_name_no_box_find_nothing(): void
    {
        foreach (['is the 2316 for maria done yet?', 'what is the weather', "how much is maria earning on her 2316", '2316 for maria santos 2026?'] as $question) {
            $this->assertSame([], $this->find(Form2316Schema::class, $question), $question);
        }
        $this->assertSame(0, BirBoxMatcher::match(Form2316Schema::fields(), '2316 for maria santos 2026?', ['maria', 'santos'])['words']);
    }
}
