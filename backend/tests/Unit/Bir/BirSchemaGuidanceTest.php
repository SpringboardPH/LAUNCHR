<?php

namespace Tests\Unit\Bir;

use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

class BirSchemaGuidanceTest extends TestCase
{
    public static function schemas(): array
    {
        return ['1601-C' => [Form1601CSchema::class], '2316' => [Form2316Schema::class]];
    }

    #[DataProvider('schemas')]
    public function test_every_field_has_help_text(string $schema): void
    {
        foreach ($schema::fields() as $field) {
            $this->assertIsString($field['guidance'], $field['key']);
            $this->assertNotSame('', trim($field['guidance']), $field['key']);
        }
    }

    #[DataProvider('schemas')]
    public function test_help_text_is_only_written_for_fields_that_exist(string $schema): void
    {
        $guidance = (new ReflectionClassConstant($schema, 'GUIDANCE'))->getValue();

        $this->assertSame([], array_diff(array_keys($guidance), array_column($schema::fields(), 'key')));
    }
}
