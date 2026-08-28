<?php

namespace App\DTO;

use Carbon\Carbon;

class PriceEstimate
{
    public function __construct(
        public float $value,
        public string $source, // 'historical_avg' | 'last_quoted'
        public ?int $dataPoints = null,
        public ?int $periodMonths = null,
        public ?Carbon $referenceDate = null,
    ) {}
}
