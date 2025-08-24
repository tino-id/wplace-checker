<?php

namespace App\Commands;

use App\Image;
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
        $this->tileDownloader  = new TileDownloader();
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
        $pixelcount = (int) $input->getArgument('pixelcount');

        $this->imageComparator->setMaxDifferencesToReport($pixelcount);

        $projectDir = __DIR__ . DS . '..' . DS . '..' . DS . 'projects' . DS . $project;

        $output->writeln('');
        $this->info('Processing: <comment>' . $project . '</comment>');
        $this->processProject($project, $projectDir, $pixelcount);

        $this->tileDownloader->clearCache();

        return self::SUCCESS;
    }

    private function processProject(string $project, string $projectDir): void
    {
        $config = $this->readConfig($project, $projectDir);

        if (!$config) {
            return;
        }

        $localImage = $this->fetchLocalImage($config);

        if (!$localImage) {
            return;
        }

        try {
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
            $color = $this->getColor($diff['colorLocal']['red'], $diff['colorLocal']['green'],
                $diff['colorLocal']['blue']);

            if ($color === null) {
                $this->error('Could not find color for: ' . json_encode($diff['colorLocal']));
                continue;
            }

            $fix['coords'][] = $diff['positionRemote']['x'] . ',' . $diff['positionRemote']['y'];
            $fix['colors'][] = $color;
        }

        $fixMessage = '{"colors": [' . implode(',', $fix['colors']) . '], "coords":[' . implode(',', $fix['coords']) . ']}';
        $this->info('Fix: ' . $fixMessage);
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

        if (!$config) {
            $this->error('Invalid config found for project: ' . $project);
        }

        if (isset($config['disabled']) && $config['disabled']) {
            $this->info('Project is disabled');

            return null;
        }

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
}