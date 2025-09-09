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

#[AsCommand(name: 'color-check', description: 'check missing colors')]
class ColorCheckCommand extends AbstractCommand
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
        $this->addArgument('project', InputArgument::REQUIRED, 'project name');
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $project    = $input->getArgument('project');
        $projectDir = $this->pathService->getProjectPath($project);

        $output->writeln('');
        $this->info('Processing: <comment>' . $project . '</comment>');
        $this->processProject($projectDir);

        $this->tileDownloader->clearCache();

        return self::SUCCESS;
    }

    private function processProject(string $projectDir): void
    {
        try {
            $config = $this->configService->readProjectConfig($projectDir);

            if (!$config) {
                $this->info('Project is disabled or invalid');

                return;
            }
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
            $localImage->getWidth() * $localImage->getHeight(),
        );

        $localImage->destroy();
        $remoteImage->destroy();

        if ($result->matchingPixels === $result->totalPixels) {
            $this->info('No differences found');

            return;
        }


        $missingColors = [];
        $message = $result->getMissingPixelsFormatted().' missing pixels'.PHP_EOL;

        // get missing colors
        foreach ($result->differences as $diff) {
            /** @var $diff ImageComparisonResultDifference */

            if (!isset($missingColors[$diff->color])) {
                $missingColors[$diff->color] = 0;
            }

            $missingColors[$diff->color]++;
        }

        arsort($missingColors);

        // load profiles
        $profiles = [];
        $profilesArray   = Yaml::parseFile($this->pathService->getProfilesConfigPath());

        foreach ($profilesArray as $profileName => $profileData) {
            $profiles[$profileName] = explode(',', $profileData['colors']);
        }

        unset($profilesArray);


        // load color config
        $colorsArray = Yaml::parseFile($this->pathService->getColorsConfigPath());
        $colors = [];

        foreach ($colorsArray as $rgb => $colorData) {
            $colors[$colorData['id']] = $colorData;
        }

        unset($colorsArray);


        // create message
        foreach ($missingColors as $color => $count) {
            $message .= $colors[$color]['name'].' (#'.$color.')';

            if ($colors[$color]['premium']) {
                foreach ($profiles as $profileName => $profileColors) {
                    if (in_array($color, $profileColors)) {
                        $message .= ' [' . $profileName . ']';
                    }
                }
            } else {
                $message .= ' [free]';
            }

            $message .= ': '. $count.PHP_EOL;
        }


        $this->output->writeln($message);
    }
}
