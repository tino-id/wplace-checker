<?php

namespace App\Services;

use App\Image;
use Exception;

class TileDownloader
{
    private int $tileSizeX;
    private int $tileSizeY;
    private string $tileUrl;
    private int $timeout;
    private string $userAgent;
    private array $tileCache = [];

    public function __construct(
        int $tileSizeX = 1000,
        int $tileSizeY = 1000,
        string $tileUrl = 'https://backend.wplace.live/files/s0/tiles/{X}/{Y}.png',
        int $timeout = 30,
        string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36'
    ) {
        $this->tileSizeX = $tileSizeX;
        $this->tileSizeY = $tileSizeY;
        $this->tileUrl = $tileUrl;
        $this->timeout = $timeout;
        $this->userAgent = $userAgent;
    }

    public function createRemoteImage(Image $localImage, array $config): ?Image
    {
        try {
            $tilesX = $this->calculateRequiredTiles($config['offsetX'] + $localImage->getWidth(), $this->tileSizeX);
            $tilesY = $this->calculateRequiredTiles($config['offsetY'] + $localImage->getHeight(), $this->tileSizeY);

            $canvas = $this->createCanvas($tilesX, $tilesY);

            for ($x = 1; $x <= $tilesX; $x++) {
                for ($y = 1; $y <= $tilesY; $y++) {
                    $tileX = $config['tileX'] + $x - 1;
                    $tileY = $config['tileY'] + $y - 1;

                    $tile = $this->getTileWithCache($tileX, $tileY);
                    
                    if (!$tile) {
                        imagedestroy($canvas);
                        throw new Exception("Failed to download tile at ({$tileX}, {$tileY})");
                    }

                    if (!$this->isValidTile($tile)) {
                        imagedestroy($tile);
                        imagedestroy($canvas);
                        throw new Exception("Invalid tile dimensions at ({$tileX}, {$tileY})");
                    }

                    $this->copyTileToCanvas($canvas, $tile, ($x - 1) * $this->tileSizeX, ($y - 1) * $this->tileSizeY);
                    imagedestroy($tile);
                }
            }

            return new Image(imagesx($canvas), imagesy($canvas), $canvas);

        } catch (Exception $e) {
            error_log("TileDownloader error: " . $e->getMessage());
            return null;
        }
    }

    private function getTileWithCache(int $tileX, int $tileY): ?\GdImage
    {
        $cacheKey = "{$tileX}_{$tileY}";
        
        // Check memory cache first
        if (isset($this->tileCache[$cacheKey])) {
            return $this->loadTileFromCache($this->tileCache[$cacheKey]);
        }
        
        // Download tile
        $tile = $this->downloadTile($tileX, $tileY);
        
        if ($tile) {
            // Cache the tile
            $cacheFile = $this->cacheTile($tile, $cacheKey);
            $this->tileCache[$cacheKey] = $cacheFile;
            
            // Return a copy since the original will be destroyed
            return $this->loadTileFromCache($cacheFile);
        }
        
        return null;
    }

    private function cacheTile(\GdImage $tile, string $cacheKey): string
    {
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "tile_cache_{$cacheKey}.png";
        imagepng($tile, $cacheFile);
        
        return $cacheFile;
    }

    private function loadTileFromCache(string $cacheFile): ?\GdImage
    {
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        $tile = imagecreatefrompng($cacheFile);
        return $tile !== false ? $tile : null;
    }

    private function downloadTile(int $tileX, int $tileY): ?\GdImage
    {
        $url = $this->buildTileUrl($tileX, $tileY);
        
        try {
            $context = $this->createHttpContext();
            $tileData = file_get_contents($url, false, $context);

            if ($tileData === false) {
                return null;
            }

            $tile = imagecreatefromstring($tileData);
            return $tile !== false ? $tile : null;

        } catch (Exception $e) {
            error_log("Failed to download tile from {$url}: " . $e->getMessage());
            return null;
        }
    }

    private function buildTileUrl(int $tileX, int $tileY): string
    {
        return str_replace(['{X}', '{Y}'], [(string)$tileX, (string)$tileY], $this->tileUrl);
    }

    private function createHttpContext()
    {
        return stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'user_agent' => $this->userAgent,
                'method' => 'GET',
                'header' => 'Accept: image/png'
            ]
        ]);
    }

    private function calculateRequiredTiles(int $pixels, int $tileSize): int
    {
        return (int)ceil($pixels / $tileSize);
    }

    private function createCanvas(int $tilesX, int $tilesY): \GdImage
    {
        $width = $tilesX * $this->tileSizeX;
        $height = $tilesY * $this->tileSizeY;
        
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        return $canvas;
    }

    private function isValidTile(\GdImage $tile): bool
    {
        return imagesx($tile) === $this->tileSizeX && imagesy($tile) === $this->tileSizeY;
    }

    private function copyTileToCanvas(\GdImage $canvas, \GdImage $tile, int $destX, int $destY): void
    {
        imagecopy(
            $canvas,
            $tile,
            $destX,
            $destY,
            0,
            0,
            imagesx($tile),
            imagesy($tile)
        );
    }

    public function clearCache(): void
    {
        foreach ($this->tileCache as $cacheFile) {
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
            }
        }
        
        $this->tileCache = [];
    }

    public function getCacheStats(): array
    {
        return [
            'cached_tiles' => count($this->tileCache),
            'cache_size_bytes' => $this->calculateCacheSize()
        ];
    }

    private function calculateCacheSize(): int
    {
        $totalSize = 0;
        foreach ($this->tileCache as $cacheFile) {
            if (file_exists($cacheFile)) {
                $totalSize += filesize($cacheFile);
            }
        }
        return $totalSize;
    }
}