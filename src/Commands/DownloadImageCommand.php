<?php

namespace App\Commands;

use App\Services\TileDownloader;
use App\Image;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'download-image', description: 'Download and crop image from tiles')]
class DownloadImageCommand extends Command
{
    private OutputInterface $output;
    private TileDownloader $tileDownloader;

    public function __construct()
    {
        parent::__construct();
        $this->tileDownloader = new TileDownloader();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tileX', InputArgument::REQUIRED, 'Starting tile X coordinate')
            ->addArgument('tileY', InputArgument::REQUIRED, 'Starting tile Y coordinate')
            ->addArgument('pixelX', InputArgument::REQUIRED, 'Starting pixel X coordinate within tile')
            ->addArgument('pixelY', InputArgument::REQUIRED, 'Starting pixel Y coordinate within tile')
            ->addArgument('width', InputArgument::REQUIRED, 'Width of the image to crop')
            ->addArgument('height', InputArgument::REQUIRED, 'Height of the image to crop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $tileX = (int) $input->getArgument('tileX');
        $tileY = (int) $input->getArgument('tileY');
        $pixelX = (int) $input->getArgument('pixelX');
        $pixelY = (int) $input->getArgument('pixelY');
        $width = (int) $input->getArgument('width');
        $height = (int) $input->getArgument('height');

        $this->info(sprintf(
            'Downloading image from tile (%d, %d) at pixel (%d, %d) with size %dx%d',
            $tileX, $tileY, $pixelX, $pixelY, $width, $height
        ));

        try {
            $remoteImage = $this->downloadTiles($tileX, $tileY, $pixelX, $pixelY, $width, $height);
            
            if (!$remoteImage) {
                $this->error('Failed to download tiles');
                return Command::FAILURE;
            }

            $croppedImage = $this->cropImage($remoteImage, $pixelX, $pixelY, $width, $height);
            
            if (!$croppedImage) {
                $this->error('Failed to crop image');
                $remoteImage->destroy();
                return Command::FAILURE;
            }

            $filename = $this->saveImage($croppedImage, $tileX, $tileY, $pixelX, $pixelY, $width, $height);
            
            if ($filename) {
                $this->info('Image saved as: ' . $filename);
            } else {
                $this->error('Failed to save image');
                return Command::FAILURE;
            }

            $remoteImage->destroy();
            imagedestroy($croppedImage);

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function downloadTiles(int $tileX, int $tileY, int $pixelX, int $pixelY, int $width, int $height): ?Image
    {
        $config = [
            'tileX' => $tileX,
            'tileY' => $tileY,
            'offsetX' => $pixelX,
            'offsetY' => $pixelY,
        ];

        $dummyImage = new Image($width, $height, imagecreatetruecolor($width, $height));
        
        try {
            $remoteImage = $this->tileDownloader->createRemoteImage($dummyImage, $config);
            $dummyImage->destroy();
            
            return $remoteImage;
        } catch (\Exception $e) {
            $dummyImage->destroy();
            throw $e;
        }
    }

    private function cropImage(Image $sourceImage, int $startX, int $startY, int $width, int $height): ?\GdImage
    {
        $croppedImage = imagecreatetruecolor($width, $height);
        imagealphablending($croppedImage, false);
        imagesavealpha($croppedImage, true);

        $success = imagecopy(
            $croppedImage,
            $sourceImage->getImage(),
            0,
            0,
            $startX,
            $startY,
            $width,
            $height
        );

        return $success ? $croppedImage : null;
    }

    private function saveImage(\GdImage $image, int $tileX, int $tileY, int $pixelX, int $pixelY, int $width, int $height): ?string
    {
        $filename = sprintf(
            'downloaded_image_t%d_%d_p%d_%d_s%dx%d_%s.png',
            $tileX, $tileY, $pixelX, $pixelY, $width, $height,
            date('Y-m-d_H-i-s')
        );
        
        $filepath = getcwd() . DIRECTORY_SEPARATOR . $filename;
        
        if (imagepng($image, $filepath)) {
            return $filename;
        }
        
        return null;
    }

    private function info(string $message): void
    {
        $this->output->writeln('<info>[INF] ' . date('Y-m-d H:i:s ') . $message . '</info>');
    }

    private function error(string $message): void
    {
        $this->output->writeln('<error>[ERR] ' . date('Y-m-d H:i:s ') . $message . '</error>');
    }
}