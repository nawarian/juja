<?php

declare(strict_types=1);

namespace Nawarian\KFStats\Commands\AttackQueue;

use Nawarian\KFStats\Commands\Traits\{AuthenticationTrait, ClearScreenTrait};
use Nawarian\KFStats\Entities\Player\PlayerRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class EnqueuePlayer extends Command
{
    use AuthenticationTrait;
    use ClearScreenTrait;

    private PlayerRepository $playerRepository;

    public function __construct(PlayerRepository $playerRepository)
    {
        parent::__construct('EnqueuePlayer');
        $this->playerRepository = $playerRepository;
    }

    protected function configure()
    {
        $this->addOption('player-url', 'p');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $queue = explode(PHP_EOL, file_get_contents(__DIR__ . '/../../../attack.queue') ?: '');
        $queue = array_unique(array_filter($queue));

        $newUrl = $input->getOption('player-url');

        if (in_array($newUrl, $queue) === false) {
            file_put_contents(__DIR__ . '/../../../attack.queue', $newUrl . PHP_EOL, FILE_APPEND);
        } else {
            $style->note('Player already enqueued, skipping...');
        }

        return 0;
    }
}
