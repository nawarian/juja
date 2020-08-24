<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use GuzzleHttp\Client;
use Nawarian\KFStats\Entities\Player\PlayerRepository;
use Nawarian\KFStats\Repositories\InMemory\PlayerRepository as InMemoryPlayerRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\Console\Input\{ArgvInput, InputInterface};
use Symfony\Component\Console\Output\{OutputInterface, ConsoleOutput};

use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestFactoryInterface, UriFactoryInterface};
use function DI\autowire;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        ClientInterface::class => function (ContainerInterface $container) {
            return $container->get(Client::class);
        },
        RequestFactoryInterface::class => autowire(Psr17Factory::class),
        UriFactoryInterface::class => autowire(Psr17Factory::class),

        InputInterface::class => autowire(ArgvInput::class),
        OutputInterface::class => autowire(ConsoleOutput::class),

        PlayerRepository::class => autowire(InMemoryPlayerRepository::class),
    ]);
};
