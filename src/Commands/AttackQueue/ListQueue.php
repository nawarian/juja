<?php

declare(strict_types=1);

namespace Nawarian\KFStats\Commands\AttackQueue;

use Nawarian\KFStats\Commands\Traits\{AuthenticationTrait, ClearScreenTrait};
use Nawarian\KFStats\Entities\Player\PlayerRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ListQueue extends Command
{
    use AuthenticationTrait;
    use ClearScreenTrait;

    private PlayerRepository $playerRepository;

    public function __construct(PlayerRepository $playerRepository)
    {
        parent::__construct('ListQueue');
        $this->playerRepository = $playerRepository;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $queue = explode(PHP_EOL, file_get_contents(__DIR__ . '/../../../attack.queue') ?: '');
        $queue = array_unique(array_filter($queue));

        if ($queue === []) {
            $style->note('Current attack queue is empty!');
        }

        $queue = array_map(function (string $url) {
            preg_match('#\/player\/([0-9]+)\/?#', $url, $matches);

            list ($url, $playerId) = $matches;

            return $this->playerRepository->fetchById((int) $playerId);
        }, $queue);

        // @todo -> remove duplicated code
        $playerRows = [];
        foreach ($queue as $player) {
            $playerRows[] = [
                $player->id,
                $player->name,
                $player->level,
                $player->strength,
                $player->stamina,
                $player->dexterity,
                $player->fightingAbility,
                $player->parry,
                str_pad(number_format($player->goldReceived, 0, '', '.'), 7, ' ', STR_PAD_LEFT),
                str_pad(number_format($player->goldLost, 0, '', '.'), 7, ' ', STR_PAD_LEFT),
            ];
        }

        $style->title('Current attack queue (attacks will happen automatically)');
        $style->table(
            ['ID', 'Name', 'Level', 'Str', 'Sta', 'Dex', 'Fight Ability', 'Parry', 'Gold +', 'Gold -'],
            $playerRows,
        );
        return 0;
    }
}
