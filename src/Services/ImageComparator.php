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

    public function compareImages(array $config, Image $localImage, Image $remoteImage, int $pixelCount = 0, ?array $availableColors = null): ImageComparisonResult
    {
        $matchingPixels = 0;
        $totalPixels    = 0;
        $differences    = [];

        for ($x = 0; $x < $localImage->getWidth(); $x++) {
            for ($y = 0; $y < $localImage->getHeight(); $y++) {
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
