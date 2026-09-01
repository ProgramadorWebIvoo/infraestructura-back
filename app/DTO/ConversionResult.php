<?php

namespace App\DTO;

use Carbon\Carbon;

class ConversionResult
{
    public function __construct(
        public float $amountConverted,
        public float $rate,
        public Carbon $rateDate,
        public bool $isOutdated = false,
    ) {}

    /**
     * Indica si la tasa de cambio está desactualizada (>24 horas).
     */
    public function rateAgeHours(): float
    {
        return $this->rateDate->diffInHours(now());
    }
}
