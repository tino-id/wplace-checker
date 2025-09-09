<?php

namespace App\Commands;

use App\Dtos\ImageComparisonResultDifference;
use App\Services\PathService;
use App\Services\TileDownloader;
use App\Services\ImageComparator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'fix-string', description: 'gets fix string for image')]
class FixStringCommand extends AbstractCommand
{
    private TileDownloader  $tileDownloader;
    private ImageComparator $imageComparator;
    private PathService     $pathService;

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
            ->addArgument('project', InputArgument::REQUIRED, 'project name')
            ->addArgument('pixelcount', InputArgument::REQUIRED, 'number of pixels to fix')
            ->addArgument('profile', InputArgument::OPTIONAL, 'user profile to use for filtering available colors');
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $project         = $input->getArgument('project');
        $projectDir      = $this->pathService->getProjectPath($project);
        $pixelCount      = (int)$input->getArgument('pixelcount');
        $profile         = $input->getArgument('profile');
        $availableColors = null;

        // load profile
        if ($profile) {
            $profiles = Yaml::parseFile($this->pathService->getProfilesConfigPath());

            if (!isset($profiles[$profile])) {
                $this->error(sprintf('Profile "%s" not found', $profile));

                return self::FAILURE;
            }

            $availableColors = explode(',', $profiles[$profile]['colors']);
        }

        $output->writeln('');
        $this->info('Processing: <comment>' . $project . '</comment>');
        $this->processProject($projectDir, $pixelCount, $availableColors);

        $this->tileDownloader->clearCache();

        return self::SUCCESS;
    }

    private function processProject(string $projectDir, int $pixelCount, ?array $availableColors): void
    {
        try {
            $config = $this->configService->readProjectConfig($projectDir);
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return;
        }

        try {
            $localImage  = $this->imageService->loadImageFromFile($config['image']);
            $remoteImage = $this->tileDownloader->createRemoteImage($localImage, $config);
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return;
        }

        $result = $this->imageComparator->compareImages(
            $config,
            $localImage,
            $remoteImage,
            $pixelCount,
            $availableColors
        );

        $localImage->destroy();
        $remoteImage->destroy();

        if ($result->matchingPixels === $result->totalPixels) {
            $this->info('No differences found');

            return;
        }

        if (count($result->differences) === 0) {
            $this->info('No pixels with available colors found');

            return;
        }

        $fix = ['coords' => [], 'colors' => []];

        foreach ($result->differences as $diff) {
            /** @var $diff ImageComparisonResultDifference */
            $fix['coords'][] = $diff->getCoordinates();
            $fix['colors'][] = $diff->color;
        }

        $fixMessage = '{"colors": [' .
            implode(',', $fix['colors']) .
            '], "coords":[' .
            implode(',', $fix['coords']) .
            ']}';

        $this->output->writeln('');
        $this->output->writeln($fixMessage);
    }
}
