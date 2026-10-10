<?php

namespace Tests\Unit\Bir;

use App\Services\BIR\BirAnswerParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a person types in the chat, turned into the exact value PUT accepts, or asked
 * again. The rule under test: an answer is read, never estimated. "150,000" is
 * 150000.00; "around 150k" is a question back, not 150000.00.
 */
class BirAnswerParserTest extends TestCase
{
    /** @return array<string, array{string, string, mixed}> */
    public static function readable(): array
    {
        return [
            'amount with commas' => ['decimal', '150,000', '150000.00'],
            'amount with a peso sign and one decimal' => ['decimal', '₱150,000.5', '150000.50'],
            'amount written with PHP' => ['decimal', 'PHP 1,234.56', '1234.56'],
            'zero is an answer' => ['decimal', '0', '0.00'],
            'yes in English' => ['boolean', 'Yes!', true],
            'yes in Filipino' => ['boolean', 'oo', true],
            'polite yes' => ['boolean', 'Opo', true],
            'polite no' => ['boolean', 'hindi po', false],
            'none means no' => ['boolean', 'wala', false],
            'date as MM/DD/YYYY' => ['date', '05/31/1990', '1990-05-31'],
            'date without a leading zero' => ['date', '5/31/1990', '1990-05-31'],
            'date in words' => ['date', 'May 31, 1990', '1990-05-31'],
            'date in lower case words' => ['date', 'may 31, 1990', '1990-05-31'],
            'date as stored' => ['date', '1990-05-31', '1990-05-31'],
            'month as M/YYYY' => ['month', '8/2026', '08/2026'],
            'month as YYYY-MM' => ['month', '2026-08', '08/2026'],
            'whole number' => ['integer', '2', 2],
            'option in another case' => ['enum', 'Main', 'main'],
            'text is trimmed' => ['string', '  049  ', '049'],
            'an address is not mistaken for a question' => ['text', '12 Mabini St., Quezon City', '12 Mabini St., Quezon City'],
        ];
    }

    #[DataProvider('readable')]
    public function test_a_readable_answer_becomes_the_value_put_accepts(string $type, string $answer, mixed $value): void
    {
        $result = BirAnswerParser::parse($answer, $this->field($type));

        $this->assertTrue($result['ok'], (string) $result['problem']);
        $this->assertSame($value, $result['value']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function unreadable(): array
    {
        return [
            'an approximate amount' => ['decimal', 'around 150k', 'exact amount'],
            'amount in Filipino approximation' => ['decimal', 'mga 150,000', 'exact amount'],
            'amount in thousands shorthand' => ['decimal', '150k', 'full amount'],
            'dot used as a thousands separator' => ['decimal', '150.000', 'amount in pesos'],
            'a negative amount' => ['decimal', '-500', 'amount in pesos'],
            'none for an amount' => ['decimal', 'wala', 'Enter 0'],
            'not yes or no' => ['boolean', 'maybe', 'yes or no'],
            'unsure in Filipino' => ['boolean', 'di ko alam', 'yes or no'],
            'a day that does not exist' => ['date', '02/30/1990', 'MM/DD/YYYY'],
            'day before month' => ['date', '31/05/1990', 'MM/DD/YYYY'],
            'a relative date' => ['date', 'yesterday', 'MM/DD/YYYY'],
            'month 13' => ['month', '13/2026', 'MM/YYYY'],
            'number in words' => ['integer', 'two', 'whole number'],
            'not one of the options' => ['enum', 'other', 'main, secondary'],
            'nothing typed' => ['string', '   ', 'type an answer'],
            'a question instead of text' => ['string', 'saan galing ito?', 'looks like a question'],
            'a question without a question mark' => ['text', 'what goes here', 'looks like a question'],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_an_unreadable_answer_is_asked_again_with_a_message_the_user_can_act_on(string $type, string $answer, string $hint): void
    {
        $result = BirAnswerParser::parse($answer, $this->field($type));

        $this->assertFalse($result['ok']);
        $this->assertNull($result['value']);
        $this->assertStringContainsString($hint, $result['problem']);
    }

    public function test_a_field_signed_by_hand_is_never_taken_from_the_chat(): void
    {
        $result = BirAnswerParser::parse('Juan', $this->field('manual'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("can't be entered in the chat", $result['problem']);
    }

    /** @return array<string, mixed> */
    private function field(string $type): array
    {
        return ['key' => 'example', 'label' => 'Example', 'type' => $type, 'options' => ['main', 'secondary']];
    }
}
