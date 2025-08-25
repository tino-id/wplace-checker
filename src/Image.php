<?php

namespace App;

use GdImage;

class Image
{
    private int    $width;
    private int    $height;
    public GdImage $image;

    public function __construct(int $width, int $height, GdImage $image)
    {
        $this->width  = $width;
        $this->height = $height;
        $this->image  = $image;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getImage(): GdImage
    {
        return $this->image;
    }

    public function destroy()
    {
        imagedestroy($this->image);
    }
}
