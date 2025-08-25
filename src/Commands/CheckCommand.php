<?php

namespace App\Commands;

use App\Image;
use App\Pushover;
use App\Services\TileDownloader;
use App\Services\ImageComparator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'check', description: 'check placed artworks from projects directory')]
class CheckCommand extends AbstractCommand
{
    private TileDownloader  $tileDownloader;
    private ImageComparator $imageComparator;
    private ?Pushover       $pushover = null;
    private ?array          $colors   = null;

    public function __construct()
    {
        parent::__construct();
        $this->tileDownloader  = new TileDownloader($this->imageService);
        $this->imageComparator = new ImageComparator();

        $pushoverConfigFile = __DIR__ . DS . '..' . DS . '..' . DS . 'config' . DS . 'pushover.yaml';

        if (file_exists($pushoverConfigFile)) {
            $pushoverConfig = Yaml::parseFile($pushoverConfigFile);
            $this->pushover = new Pushover($pushoverConfig['token'], $pushoverConfig['user']);
        }
    }

    public function __invoke(OutputInterface $output): int
    {
        $this->output = $output;

        $projects = glob(__DIR__ . DS . '..' . DS . '..' . DS . 'projects' . DS . '*');

        foreach ($projects as $projectDir) {
            $project = basename($projectDir);

            if (str_starts_with($project, '_') || !is_dir($projectDir)) {
                $this->debug('Skipping: ' . $project);
                continue;
            }

            $output->writeln('');
            $this->info('Processing: <comment>' . $project . '</comment>');
            $this->processProject($project, $projectDir);
        }

        if ($this->output->isDebug()) {
            $cacheStats = $this->tileDownloader->getCacheStats();
            $this->debug(
                sprintf(
                    'Cache-Stats: %d Tiles, %.2f MB',
                    $cacheStats['cached_tiles'],
                    $cacheStats['cache_size_bytes'] / 1024 / 1024
                )
            );
        }

        $this->tileDownloader->clearCache();

        return self::SUCCESS;
    }

    private function processProject(string $project, string $projectDir): void
    {
        try {
            $config = $this->configService->readProjectConfig($projectDir);
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return;
        }

        if (!$config || ($config['disableCheck'] ?? false)) {
            $this->info('Project is disabled or invalid');

            return;
        }

        try {
            $localImage = $this->imageService->loadImageFromFile($config['image']);
            $remoteImage = $this->tileDownloader->createRemoteImage($localImage, $config);
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return;
        }

        $result = $this->imageComparator->compareImages($config, $localImage, $remoteImage);

        if (empty($result['differences'])) {
            $this->info('No differences found');

            return;
        }

        $this->info(sprintf(
            'Matching Pixel: %d of %d (%.2f%%)',
            $result['matchingPixels'],
            $result['totalPixels'],
            $result['matchPercentage']
        ));

        $this->error('First ' . count($result['differences']) . ' differences:');
        foreach ($result['differences'] as $diff) {
            $message =
                'Local (' . $diff['positionLocal']['x'] . ',' . $diff['positionLocal']['y'] . '): ' .
                $diff['colorLocal']['red'] . ',' . $diff['colorLocal']['green'] . ',' . $diff['colorLocal']['blue'] . ' / ' .
                'Remote (' . $diff['positionRemote']['x'] . ',' . $diff['positionRemote']['y'] . '): ' .
                $diff['colorRemote']['red'] . ',' . $diff['colorRemote']['green'] . ',' . $diff['colorRemote']['blue'];

            $this->error($message);
        }

        if ($this->pushover !== null) {
            $this->sendPushover($localImage, $remoteImage, $config, $project, $result);
        }

        $localImage->destroy();
        $remoteImage->destroy();
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
        $message .= sprintf(
            'Übereinstimmende Pixel: %d von %d (%.2f%%)',
            $result['matchingPixels'],
            $result['totalPixels'],
            $result['matchPercentage']
        );

        $this->pushover->send($message, $croppedImagePath);

        unlink($croppedImagePath);
    }
}
