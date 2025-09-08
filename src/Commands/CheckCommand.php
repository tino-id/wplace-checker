<?php

namespace App\Commands;

use App\Image;
use App\Pushover;
use App\Services\PathService;
use App\Services\TileDownloader;
use App\Services\ImageComparator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'check', description: 'check placed artworks from projects directory')]
class CheckCommand extends AbstractCommand
{
    private TileDownloader  $tileDownloader;
    private ImageComparator $imageComparator;
    private ?Pushover       $pushover = null;
    private PathService    $pathService;

    public function __construct()
    {
        parent::__construct();
        $this->tileDownloader  = new TileDownloader($this->imageService);
        $this->imageComparator = new ImageComparator();
        $this->pathService     = new PathService();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projects', InputArgument::IS_ARRAY, 'check given projects')
            ->addOption('push', 'p', InputOption::VALUE_OPTIONAL, 'send push messages', "true", ["true", "false"]);
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $projects     = $input->getArgument('projects');
        $sendPush     = $input->getOption('push');

        $pushoverConfigPath = $this->pathService->getPushoverConfigPath();

        if (file_exists($pushoverConfigPath) && $sendPush === 'true') {
            $pushoverConfig = Yaml::parseFile($pushoverConfigPath);
            $this->pushover = new Pushover($pushoverConfig['token'], $pushoverConfig['user']);
        }

        $projectDirs = glob($this->pathService->getProjectsPath() . '/*');

        foreach ($projectDirs as $projectDir) {
            $projectName = basename($projectDir);

            if (count($projects) && !in_array($projectName, $projects)) {
                continue;
            }

            if (str_starts_with($projectName, '_') || !is_dir($projectDir)) {
                $this->debug('Skipping: ' . $projectName);
                continue;
            }

            $output->writeln('');
            $this->info('Processing: <comment>' . $projectName . '</comment>');
            $this->processProject($projectName, $projectDir);
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

    private function processProject(string $projectName, string $projectDir): void
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
            $localImage  = $this->imageService->loadImageFromFile($config['image']);
            $remoteImage = $this->tileDownloader->createRemoteImage($localImage, $config);
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return;
        }

        $result = $this->imageComparator->compareImages($config, $localImage, $remoteImage);

        if ($result->matchingPixels === $result->totalPixels) {
            $this->info('No differences found');

            return;
        }

        $resultMessage = sprintf(
            'Matching Pixel: %s of %s (%.2f%%)',
            $result->getMatchingPixelsFormatted(),
            $result->getTotalPixelsFormatted(),
            $result->getMatchPercentage(),
        );

        $this->info($resultMessage);

        if ($this->pushover !== null) {
            $this->sendPushover($localImage, $remoteImage, $config, $projectName, $resultMessage);
        }

        $localImage->destroy();
        $remoteImage->destroy();
    }


    private function sendPushover(
        Image $localImage,
        Image $remoteImage,
        array $config,
        string $projectName,
        string $resultMessage
    ) {
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

        $message = 'Project: ' . $projectName . "\n";
        $message .= $resultMessage;

        $this->pushover->send($message, $croppedImagePath);

        unlink($croppedImagePath);
    }
}
