<?php

namespace Tests\Unit;

use App\Models\Contractor;
use PHPUnit\Framework\TestCase;

class ContractorNormalizeRifTest extends TestCase
{
    /**
     * @dataProvider equivalentFormatsProvider
     */
    public function test_normalizes_all_equivalent_dash_formats_to_the_same_canonical_string(string $input): void
    {
        $this->assertSame('J-12345678-9', Contractor::normalizeRif($input));
    }

    public static function equivalentFormatsProvider(): array
    {
        return [
            'no dashes' => ['J123456789'],
            'dash after letter only' => ['J-123456789'],
            'dash before last digit only' => ['J12345678-9'],
            'both dashes' => ['J-12345678-9'],
            'lowercase' => ['j-12345678-9'],
            'with surrounding whitespace' => ['  J-12345678-9  '],
        ];
    }

    public function test_leaves_non_matching_input_unchanged_for_regex_to_reject(): void
    {
        $this->assertSame('NOT-A-RIF', Contractor::normalizeRif('NOT-A-RIF'));
        $this->assertSame('', Contractor::normalizeRif(''));
        $this->assertSame('', Contractor::normalizeRif(null));
    }
}
