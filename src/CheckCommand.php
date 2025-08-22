<?php

namespace App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

define('DS', DIRECTORY_SEPARATOR);

#[AsCommand(name: 'check', description: 'check placed artworks from projects directory')]
class CheckCommand extends Command
{
    private OutputInterface $output;
    private int             $tileSizeX = 1000;
    private int             $tileSizeY = 1000;
    private string          $tileUrl   = 'https://backend.wplace.live/files/s0/tiles/{X}/{Y}.png';

    private ?Pushover $pushover = null;

    public function __invoke(OutputInterface $output): int
    {
        $this->output = $output;

        $pushoverConfigFile = __DIR__ . DS . '..' . DS . 'config' . DS . 'pushover.yaml';

        if (file_exists($pushoverConfigFile)) {
            $pushoverConfig = Yaml::parseFile($pushoverConfigFile);
            $this->pushover = new Pushover($pushoverConfig['token'], $pushoverConfig['user']);
        }

        $projects = glob(__DIR__ . '/../projects/*');

        foreach ($projects as $projectDir) {
            $project = basename($projectDir);

            if (str_starts_with($project, '_') || !is_dir($projectDir)) {
                $this->debug('Skipping: ' . $project);
                continue;
            }

            $this->info('Processing: ' . $project);
            $this->processProject($project, $projectDir);
        }

        return Command::SUCCESS;
    }

    private function processProject(string $project, string $projectDir)
    {
        $config = $this->readConfig($project, $projectDir);

        if (!$config) {
            return;
        }

        $localImage = $this->fetchLocalImage($config);

        if (!$localImage) {
            return;
        }

        $remoteImage = $this->fetchRemoteImage($localImage, $config);

        if (!$remoteImage) {
            return;
        }

        $result = $this->compareImages($config, $localImage, $remoteImage);

        if (empty($result['differences'])) {
            $this->info('Keine Unterschiede gefunden');

            return;
        }

        $this->info('Übereinstimmene Pixel: ' . $result['matchingPixels'] . ' von ' . $result['totalPixels']
            . ' (' . round($result['matchingPixels'] / $result['totalPixels'] * 100, 2) . '%)');

        $this->error("Erste " . count($result['differences']) . " Unterschiede:");
        foreach ($result['differences'] as $diff) {
            $this->error(
                "{$diff['positionLocal']}: {$diff['colorLocal']} / {$diff['positionRemote']}: {$diff['colorRemote']}"
            );
        }

        if ($this->pushover !== null) {
            $this->sendPushover($localImage, $remoteImage, $config, $project, $result);
        }

        $localImage->destroy();
        $remoteImage->destroy();
    }

    private function readConfig(string $project, string $projectDir): ?array
    {
        $configFile = $projectDir . DS . 'config.yaml';
        $this->debug('Reading config: ' . $configFile);
        $config = file_exists($configFile) ? file_get_contents($configFile) : null;

        if (!$config) {
            $this->error('No config found for project: ' . $project);

            return null;
        }

        $config = Yaml::parseFile($projectDir . DS . 'config.yaml');

        $mandatoryKeys = [
            'tileX',
            'tileY',
            'offsetX',
            'offsetY',
            'image',
        ];

        foreach ($mandatoryKeys as $key) {
            if (!isset($config[$key])) {
                $this->error('Missing "' . $key . '" in config');

                return null;
            }
        }

        $imagePath = $projectDir . DS . $config['image'];

        if (!file_exists($imagePath)) {
            $this->error('Image not found: ' . $imagePath);

            return null;
        }

        // set absolute path for image
        $config['image'] = $imagePath;

        return $config;
    }

    private function fetchRemoteImage(Image $localImage, array $config): ?Image
    {
        $tilesX = ceil(($config['offsetX'] + $localImage->getWidth()) / $this->tileSizeX);
        $tilesY = ceil(($config['offsetY'] + $localImage->getHeight()) / $this->tileSizeY);

        $image = imagecreatetruecolor($tilesX * $this->tileSizeX, $tilesY * $this->tileSizeY);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $posX = 0;
        $posY = 0;

        for ($x = 1; $x <= $tilesX; $x++) {
            for ($y = 1; $y <= $tilesY; $y++) {
                $url = str_replace('{X}', $config['tileX'] + $x - 1, $this->tileUrl);
                $url = str_replace('{Y}', $config['tileY'] + $y - 1, $url);

                $this->debug('Fetching tile: ' . $url);

                $tile = file_get_contents($url);

                if (!$tile) {
                    $this->error('Could not fetch tile: ' . $url);

                    return null;
                }

                $remoteImage = imagecreatefromstring($tile);

                if (!$remoteImage
                    || imagesx($remoteImage) != $this->tileSizeX
                    || imagesy($remoteImage) != $this->tileSizeY
                ) {
                    $this->error('Invalid tile: ' . $url);

                    return null;
                }

                imagecopy(
                    $image,
                    $remoteImage,
                    ($x - 1) * $this->tileSizeX,
                    ($y - 1) * $this->tileSizeY,
                    0,
                    0,
                    imagesx($remoteImage),
                    imagesy($remoteImage)
                );
            }
        }

        imagepng($image, '/tmp/remote.' . microtime(true) . '.png');

        return new Image(imagesx($image), imagesy($image), $image);
    }

    private function fetchLocalImage(array $config): ?Image
    {
        if (!file_exists($config['image'])) {
            $this->error('Image not found: ' . $config['image']);

            return null;
        }

        $image = imagecreatefrompng($config['image']);

        if (!$image || get_class($image) !== 'GdImage') {
            $this->error('Could not load image: ' . $config['image']);

            return null;
        }

        return new Image(imagesx($image), imagesy($image), $image);
    }

    private function compareImages(array $config, Image $localImage, Image $remoteImage): array
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

                $localR = $localRgb['red'];
                $localG = $localRgb['green'];
                $localB = $localRgb['blue'];
                $localA = $localRgb['alpha'];

                $remoteR = $remoteRgb['red'];
                $remoteG = $remoteRgb['green'];
                $remoteB = $remoteRgb['blue'];
                $remoteA = $remoteRgb['alpha'];

                if ($localA == 127) {
                    continue;
                }

                $totalPixels++;

                if ($localR === $remoteR && $localG === $remoteG && $localB === $remoteB) {
                    $matchingPixels++;

                    continue;
                }

                if (count($differences) < 10) {
                    $differences[] = [
                        'positionLocal'  => '(' . $x . ', ' . $y . ')',
                        'positionRemote' => '(' . ($x + $config['offsetX']) . ', ' . ($y + $config['offsetY']) . ')',
                        'colorLocal'     => "RGB({$localR}, {$localG}, {$localB})",
                        'colorRemote'    => "RGB({$remoteR}, {$remoteG}, {$remoteB})",
                    ];
                }

            }
        }

        return [
            'matchingPixels' => $matchingPixels,
            'totalPixels'    => $totalPixels,
            'differences'    => $differences,
        ];
    }

    private function sendPushover(Image $localImage, Image $remoteImage, array $config, string $project, array $result)
    {
        $croppedImage     = imagecreatetruecolor($localImage->getWidth(), $localImage->getHeight());
        $croppedImagePath = tempnam('/tmp', 'cropped-');

        imagecopy(
            $croppedImage,
            $remoteImage->getImage(),
            0,
            0,
            $config['offsetX'],
            $config['offsetY'],
            $localImage->getWidth(),
            $localImage->getHeight()
        );
        imagepng($croppedImage, $croppedImagePath);
        imagedestroy($croppedImage);

        $message = 'Project: ' . $project . "\n";
        $message .= 'Übereinstimmene Pixel: ' . $result['matchingPixels'] . ' von ' . $result['totalPixels']
            . ' (' . round($result['matchingPixels'] / $result['totalPixels'] * 100, 2) . '%)';

        $this->pushover->send($message, $croppedImagePath);

        unlink($croppedImagePath);
    }

    private function info(string $message)
    {
        $this->output->writeln('<info>[INF] ' . date('Y-m-d H:i:s ') . $message . '</info>');
    }

    private function error(string $message)
    {
        $this->output->writeln('<error>[ERR] ' . date('Y-m-d H:i:s ') . $message . '</error>');
    }

    private function debug(string $message)
    {
        if ($this->output->isDebug()) {
            $this->output->writeln('<comment>[DBG] ' . date('Y-m-d H:i:s ') . $message . '</comment>');
        }
    }
}