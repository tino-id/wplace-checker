<?php

namespace App\Services;

class PathService
{
    private string $rootPath;

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 2);
    }

    public function getProjectsPath(): string
    {
        return $this->buildPath('projects');
    }

    public function getProjectPath(string $projectName): string
    {
        return $this->buildPath('projects', $projectName);
    }

    public function getProjectConfigPath(string $projectName): string
    {
        return $this->buildPath('projects', $projectName, 'config.yaml');
    }

    public function getConfigPath(string $filename): string
    {
        return $this->buildPath('config', $filename);
    }

    public function getPushoverConfigPath(): string
    {
        return $this->getConfigPath('pushover.yaml');
    }

    public function getColorsConfigPath(): string
    {
        return $this->getConfigPath('colors.yaml');
    }

    public function getOutputPath(string $filename): string
    {
        return $this->buildPath($filename);
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    private function buildPath(string ...$parts): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }
}
