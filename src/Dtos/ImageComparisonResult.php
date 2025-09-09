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
        return $this->format($this->totalPixels);
    }

    public function getMatchingPixelsFormatted(): string
    {
        return $this->format($this->matchingPixels);
    }

    public function getMissingPixelsFormatted(): string
    {
        return $this->format($this->totalPixels - $this->matchingPixels);
    }

    public function getMatchPercentage(): float
    {
        return $this->totalPixels > 0 ? ($this->matchingPixels / $this->totalPixels) * 100 : 100.0;
    }

    private function format(int $number): string
    {
        return number_format($number, 0, '', '.');
    }
}
