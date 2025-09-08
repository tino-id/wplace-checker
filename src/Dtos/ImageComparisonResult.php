<?php

declare(strict_types=1);

namespace App\Dtos;

class ImageComparisonResult
{
    public function __construct(
        public int $totalPixels,
        public int $matchingPixels,
        public array $differences
    ) {

    }

    public function getTotalPixelsFormatted(): string
    {
        return number_format($this->totalPixels, 0, '', '.');
    }

    public function getMatchingPixelsFormatted(): string
    {
        return number_format($this->matchingPixels, 0, '', '.');
    }

    public function getMatchPercentage(): float
    {
        return $this->totalPixels > 0 ? ($this->matchingPixels / $this->totalPixels) * 100 : 100.0;
    }
}
