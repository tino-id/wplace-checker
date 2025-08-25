<?php

namespace App\Services;

use App\Image;

class ImageService
{
    public function loadImageFromFile(string $imagePath): Image
    {
        if (!file_exists($imagePath)) {
            throw new \InvalidArgumentException("Image not found: {$imagePath}");
        }

        $image = imagecreatefrompng($imagePath);

        if (!$image || get_class($image) !== 'GdImage') {
            throw new \RuntimeException("Could not load image: {$imagePath}");
        }

        return new Image(imagesx($image), imagesy($image), $image);
    }

    public function createTransparentImage(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        $this->setupTransparency($image);

        return $image;
    }

    public function setupTransparency(\GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
    }
}
