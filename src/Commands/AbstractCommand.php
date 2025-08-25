<?php

namespace App\Commands;

use App\Services\ConfigService;
use App\Services\ImageService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractCommand extends Command
{
    protected OutputInterface $output;
    protected ConfigService $configService;
    protected ImageService $imageService;

    protected function info(string $message)
    {
        $this->output->writeln('<info>[INF] ' . date('Y-m-d H:i:s ') . $message . '</info>');
    }

    protected function error(string $message)
    {
        $this->output->writeln('<error>[ERR] ' . date('Y-m-d H:i:s ') . $message . '</error>');
    }

    protected function debug(string $message)
    {
        if ($this->output->isDebug()) {
            $this->output->writeln('<comment>[DBG] ' . date('Y-m-d H:i:s ') . $message . '</comment>');
        }
    }

    public function __construct()
    {
        parent::__construct();
        $this->configService = new ConfigService();
        $this->imageService = new ImageService();
    }
}
