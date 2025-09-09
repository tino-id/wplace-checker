<?php

namespace App\Commands;

use App\Dtos\ImageComparisonResultDifference;
use App\Services\PathService;
use App\Services\TileDownloader;
use App\Services\ImageComparator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Helper\TableSeparator;
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

        $this->output->writeln('');
        $this->info('Missing pixels in project "' . $project.'"');
        $this->output->writeln('');
        $this->processProject($projectDir);

        $this->tileDownloader->clearCache();

        return self::SUCCESS;
    }

    private function processProject(string $projectDir): void
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
            $localImage->getWidth() * $localImage->getHeight(),
        );

        $localImage->destroy();
        $remoteImage->destroy();

        if ($result->matchingPixels === $result->totalPixels) {
            $this->info('No differences found');

            return;
        }


        $missingColors = [];

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
        $profiles      = [];
        $profilesArray = Yaml::parseFile($this->pathService->getProfilesConfigPath());

        foreach ($profilesArray as $profileName => $profileData) {
            $profiles[$profileName] = explode(',', $profileData['colors']);
        }

        unset($profilesArray);


        // load color config
        $colorsArray = Yaml::parseFile($this->pathService->getColorsConfigPath());
        $colors      = [];

        foreach ($colorsArray as $rgb => $colorData) {
            $colors[$colorData['id']] = $colorData;
            $rgbArray = explode(',', $rgb);
            $colors[$colorData['id']]['hex'] = sprintf("#%02x%02x%02x", $rgbArray[0], $rgbArray[1], $rgbArray[2]);
        }

        unset($colorsArray);

        $tableData            = [];
        $colorsWithoutProfile = 0;

        foreach ($missingColors as $color => $count) {

            $possibleProfiles = [];

            if ($colors[$color]['premium']) {
                foreach ($profiles as $profileName => $profileColors) {
                    if (in_array($color, $profileColors)) {
                        $possibleProfiles[] = $profileName;
                    }
                }

                if (count($possibleProfiles) === 0) {
                    $colorsWithoutProfile++;
                }
            } else {
                $possibleProfiles[] = 'free';
            }

            $tableData[] = [
                '<fg='.$colors[$color]['hex'].'>█</> '. $colors[$color]['name'].' (#'.$color.')',
                new TableCell(number_format($count, 0, '', '.'), ['style' => new TableCellStyle(['align' => 'right'])]),
                implode(', ', $possibleProfiles),
            ];
        }

        $tableData[] = new TableSeparator();
        $tableData[] = [
            count($missingColors) . ' diffrent colors',
            new TableCell($result->getMissingPixelsFormatted(), ['style' => new TableCellStyle(['align' => 'right'])]),
            $colorsWithoutProfile . ' colors without profiles',
        ];

        $table = new Table($this->output);
        $table->setHeaders(['Color', 'Pixel', 'Profiles']);
        $table->setStyle('box');
        $table->setRows($tableData);
        $table->render();
    }
}
