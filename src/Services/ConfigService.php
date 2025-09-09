<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;

class ConfigService
{
    private const MANDATORY_KEYS = [
        'tileX',
        'tileY',
        'offsetX',
        'offsetY',
        'image',
    ];

    public function readProjectConfig(string $projectDir): array
    {
        $configFile = $projectDir . DIRECTORY_SEPARATOR . 'config.yaml';

        if (!file_exists($configFile)) {
            throw new \RuntimeException("Config file not found: {$configFile}");
        }

        $config = Yaml::parseFile($configFile);

        if (!$config || !is_array($config)) {
            throw new \RuntimeException("Could not parse config file: {$configFile}");
        }

        foreach (self::MANDATORY_KEYS as $key) {
            if (!isset($config[$key])) {
                throw new \InvalidArgumentException("Missing '{$key}' in config file: {$configFile}");
            }
        }

        $imagePath = $projectDir . DIRECTORY_SEPARATOR . $config['image'];

        if (!file_exists($imagePath)) {
            throw new \InvalidArgumentException("Image not found: {$imagePath}");
        }

        // replace image with absolute path
        $config['image'] = $imagePath;

        return $config;
    }
}
