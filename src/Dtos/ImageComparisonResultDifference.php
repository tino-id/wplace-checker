<?php

declare(strict_types=1);

namespace App\Dtos;

class ImageComparisonResultDifference
{
    public function __construct(
        public int $x,
        public int $y,
        public int $color
    ) {
    }

    public function getCoordinates(): string
    {
        return $this->x . ',' . $this->y;
    }
}
