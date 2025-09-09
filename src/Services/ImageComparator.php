<?php

namespace App\Services;

use App\Dtos\ImageComparisonResult;
use App\Dtos\ImageComparisonResultDifference;
use App\Image;
use Symfony\Component\Yaml\Yaml;

class ImageComparator
{
    private ?array $colors = null;
    private PathService $pathService;

    public function __construct()
    {
        $this->pathService = new PathService();
    }

    public function compareImages(array $config, Image $localImage, Image $remoteImage, int $pixelCount = 0, ?array $availableColors = null, string $direction = 'top'): ImageComparisonResult
    {
        $matchingPixels = 0;
        $totalPixels    = 0;
        $differences    = [];

        $width = $localImage->getWidth();
        $height = $localImage->getHeight();

        // Determine iteration ranges based on direction
        switch ($direction) {
            case 'bottom':
                $yRange = range($height - 1, 0);
                $xRange = range(0, $width - 1);
                break;
            case 'left':
                $yRange = range(0, $height - 1);
                $xRange = range(0, $width - 1);
                $swapAxes = true;
                break;
            case 'right':
                $yRange = range(0, $height - 1);
                $xRange = range($width - 1, 0);
                $swapAxes = true;
                break;
            default: // 'top'
                $yRange = range(0, $height - 1);
                $xRange = range(0, $width - 1);
                break;
        }

        $outerRange = $swapAxes ?? false ? $xRange : $yRange;
        $innerRange = $swapAxes ?? false ? $yRange : $xRange;

        foreach ($outerRange as $outer) {
            foreach ($innerRange as $inner) {
                $x = ($swapAxes ?? false) ? $outer : $inner;
                $y = ($swapAxes ?? false) ? $inner : $outer;

                $localColor  = imagecolorat($localImage->getImage(), $x, $y);
                $remoteColor = imagecolorat(
                    $remoteImage->getImage(),
                    $x + $config['offsetX'],
                    $y + $config['offsetY']
                );

                $localRgb  = imagecolorsforindex($localImage->getImage(), $localColor);
                $remoteRgb = imagecolorsforindex($remoteImage->getImage(), $remoteColor);

                if ($this->isTransparent($localRgb)) {
                    continue;
                }

                $totalPixels++;

                if ($this->colorsMatch($localRgb, $remoteRgb)) {
                    $matchingPixels++;
                    continue;
                }

                if (count($differences) < $pixelCount) {
                    $colorId = $this->getColorId($localRgb);

                    if (!$colorId) {
                        continue;
                    }

                    if ($availableColors && !in_array($colorId, $availableColors)) {
                        continue;
                    }

                    $differences[] = new ImageComparisonResultDifference($x + $config['offsetX'], $y + $config['offsetY'], $colorId);
                }
            }
        }

        return new ImageComparisonResult($totalPixels, $matchingPixels, $differences);
    }

    private function isTransparent(array $rgba): bool
    {
        return $rgba['alpha'] === 127;
    }

    private function colorsMatch(array $color1, array $color2): bool
    {
        return $color1['red'] === $color2['red'] &&
            $color1['green'] === $color2['green'] &&
            $color1['blue'] === $color2['blue'] &&
            $color1['alpha'] === $color2['alpha'];
    }

    private function getColorId(array $localRgb): ?int
    {
        if (null === $this->colors) {
            $this->colors = Yaml::parseFile($this->pathService->getColorsConfigPath());
        }

        $arrayKey = $localRgb['red'] . ',' . $localRgb['green'] . ',' . $localRgb['blue'];
        if (isset($this->colors[$arrayKey])) {
            return $this->colors[$arrayKey]['id'];
        }

        return null;
    }
}
