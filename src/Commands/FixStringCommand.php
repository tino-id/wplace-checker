<?php

namespace App\Commands;

use App\Pushover;
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
    private ?Pushover       $pushover = null;
    private ?array          $colors   = null;

    public function __construct()
    {
        parent::__construct();
        $this->tileDownloader  = new TileDownloader($this->imageService);
        $this->imageComparator = new ImageComparator();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::REQUIRED, 'project name')
            ->addArgument('pixelcount', InputArgument::REQUIRED, 'number of pixels to fix')
        ;
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $project    = $input->getArgument('project');
        $pixelCount = (int) $input->getArgument('pixelcount');

        $this->imageComparator->setMaxDifferencesToReport($pixelCount);

        $projectDir = __DIR__ . DS . '..' . DS . '..' . DS . 'projects' . DS . $project;

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
            $localImage = $this->imageService->loadImageFromFile($config['image']);
            $remoteImage = $this->tileDownloader->createRemoteImage($localImage, $config);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return;
        }

        $result = $this->imageComparator->compareImages($config, $localImage, $remoteImage);

        $localImage->destroy();
        $remoteImage->destroy();

        if (empty($result['differences'])) {
            $this->info('No differences found');

            return;
        }

        $fix = ['coords' => [], 'colors' => []];

        foreach ($result['differences'] as $diff) {
            $color = $this->getColor(
                $diff['colorLocal']['red'],
                $diff['colorLocal']['green'],
                $diff['colorLocal']['blue']
            );

            if ($color === null) {
                $this->error('Could not find color for: ' . json_encode($diff['colorLocal']));
                continue;
            }

            $fix['coords'][] = $diff['positionRemote']['x'] . ',' . $diff['positionRemote']['y'];
            $fix['colors'][] = $color;
        }

        $fixMessage = '{"colors": [' . implode(',', $fix['colors']) . '], "coords":[' . implode(',', $fix['coords']) . ']}';
        $this->output->writeln('');
        $this->output->writeln($fixMessage);
    }

    private function getColor(int $r, int $g, int $b): ?int
    {
        if (empty($this->colors)) {
            $this->colors = Yaml::parseFile(__DIR__ . DS . '..' . DS . '..' . DS . 'config' . DS . 'colors.yaml');
        }

        if (isset($this->colors[$r . ',' . $g . ',' . $b])) {
            return $this->colors[$r . ',' . $g . ',' . $b];
        }

        return null;
    }

}
