<?php

namespace App\Services;

use App\Image;

class ImageComparator
{
    private int $maxDifferencesToReport;

    public function __construct(int $maxDifferencesToReport = 100)
    {
        $this->maxDifferencesToReport = $maxDifferencesToReport;
    }

    public function compareImages(array $config, Image $localImage, Image $remoteImage): array
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

                // Record difference if we haven't reached the limit
                if (count($differences) < $this->maxDifferencesToReport) {
                    $differences[] = $this->createDifferenceRecord(
                        $x,
                        $y,
                        $localRgb,
                        $remoteRgb,
                        $config
                    );
                }
            }
        }

        return [
            'matchingPixels'  => $matchingPixels,
            'totalPixels'     => $totalPixels,
            'differences'     => $differences,
            'matchPercentage' => $totalPixels > 0 ? ($matchingPixels / $totalPixels) * 100 : 100.0,
        ];
    }

    private function isTransparent(array $rgba): bool
    {
        return $rgba['alpha'] === 127;
    }

    private function colorsMatch(array $color1, array $color2): bool
    {
        /*if ($color1['red'] !== 60 && $color1['green'] !== 60 && $color1['blue'] !== 60) {
            return true;
        }
        */

        return $color1['red'] === $color2['red'] &&
            $color1['green'] === $color2['green'] &&
            $color1['blue'] === $color2['blue'] &&
            $color1['alpha'] === $color2['alpha'];
    }

    private function createDifferenceRecord(int $x, int $y, array $localRgb, array $remoteRgb, array $config): array
    {
        return [
            'positionLocal'  => ['x' => $x, 'y' => $y],
            'positionRemote' => ['x' => $x + $config['offsetX'], 'y' => $y + $config['offsetY']],
            'colorLocal'     => $localRgb,
            'colorRemote'    => $remoteRgb,
        ];
    }

    public function setMaxDifferencesToReport(int $maxDifferencesToReport): void
    {
        $this->maxDifferencesToReport = $maxDifferencesToReport;
    }
}
