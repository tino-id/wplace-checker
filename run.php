<?php
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use App\Commands\CheckCommand;
use App\Commands\DownloadImageCommand;
use App\Commands\FixStringCommand;
use App\Commands\ColorCheckCommand;

$application = new Application();
$application->add(new CheckCommand());
$application->add(new DownloadImageCommand());
$application->add(new FixStringCommand());
$application->add(new ColorCheckCommand());
$application->run();