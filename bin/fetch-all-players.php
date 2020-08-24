<?php

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Nawarian\KFStats\Commands\FetchAllPlayers;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

require_once __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$dependencies = require __DIR__ . '/../app/dependencies.php';
$dependencies($containerBuilder);

$dotEnv = Dotenv::createUnsafeMutable(__DIR__ . '/../');
$dotEnv->load();

$container = $containerBuilder->build();

$container->get(FetchAllPlayers::class)
    ->run($container->get(InputInterface::class), $container->get(OutputInterface::class));
