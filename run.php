<?php
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use App\CheckCommand;

$application = new Application();
$application->add(new CheckCommand());
$application->run();