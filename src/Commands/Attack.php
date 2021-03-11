<?php

declare(strict_types=1);

namespace Nawarian\Juja\Commands;

use Nawarian\Juja\Commands\Traits\{AuthenticationTrait, ClearScreenTrait};
use Nawarian\Juja\Commands\AttackQueue\EnqueuePlayer;
use Nawarian\Juja\Commands\AttackQueue\ListQueue;
use Nawarian\Juja\Entities\Player\{Player, PlayerRepository};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

final class Attack extends Command
{
    use AuthenticationTrait;
    use ClearScreenTrait;

    private PlayerRepository $playerRepository;

    private Player $player;

    public function __construct(PlayerRepository $playerRepository, Player $player)
    {
        parent::__construct('Attack');

        $this->playerRepository = $playerRepository;
        $this->player = $player;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        // Present current attack list
        $listQueueCommand = new ListQueue($this->playerRepository);
        $listQueueCommand->run($input, $output);

        $choices = new ChoiceQuestion(
            'What are you looking for?',
            [
                'attack' => 'Attack a player you already know the ID',
                'farm' => 'Find weaker players that will potentially yield good money',
                'lvlup' => 'Find weaker players of higher level',
                'lvldown' => 'Find weaker players of lower level so you lose experience',
            ],
        );

        $searchType = $style->askQuestion($choices);

        $offset = 0;
        while (true) {
            $players = [];
            switch ($searchType) {
                case 'attack':
                    $playerId = $style->ask('Which player? (provide a player id)');
                    $player = $this->playerRepository->fetchById((int) $playerId);

                    $enqueueCommand = new EnqueuePlayer($this->playerRepository);
                    $enqueueCommandInput = new ArrayInput([
                        '--player-url' => $player->url,
                    ]);

                    $enqueueCommand->run($enqueueCommandInput, $output);

                    $style->ask('Press ENTER to go back');
                    break(2);
                case 'farm':
                    $players = $this->playerRepository->fetchPlayersWeakerThan($this->player, 5, $offset * 5);
                    break;
                case 'lvlup':
                    $players = $this->playerRepository->fetchPlayersWeakerAndWithHigherLevelThan($this->player, 5, $offset * 5);
                    break;
                case 'lvldown':
                    // $players = ...
                    $style->note('This is not yet implemented.');

                    $style->ask('Press ENTER to go back');
                    break(2);
                    break;
            }

            // Render whole table
            $this->clearScreen($input, $output);

            $this->renderPlayerList($output, $players);

            $choices = new ChoiceQuestion(
                'Load more or attack?',
                [
                    'attack' => 'Attack a player',
                    'load more' => 'Load more...',
                    'open' => 'Open player url',
                    'cancel' => 'Cancel, go back to main menu',
                ],
            );

            switch ($style->askQuestion($choices)) {
                case 'attack':
                    $playerId = $style->ask('Which player? (provide a player id)');
                    $player = $this->playerRepository->fetchById((int) $playerId);

                    $enqueueCommand = new EnqueuePlayer($this->playerRepository);
                    $enqueueCommandInput = new ArrayInput([
                        '--player-url' => $player->url,
                    ]);

                    $enqueueCommand->run($enqueueCommandInput, $output);

                    $style->ask('Press ENTER to go back');
                    break;
                case 'load more':
                    $offset++;
                    break;
                case 'open':
                    $playerId = $style->ask('Which player? (provide a player id)');
                    $player = $this->playerRepository->fetchById((int) $playerId);

                    $style->text("Visit this url: {$player->url}");
                    $style->ask('Press ENTER to go back');

                    break;
                case 'cancel':
                    break(2); // Leave the while loop
                default:
                    break;
            }

            $this->clearScreen($input, $output);
        }

        return 0;
    }

    private function renderPlayerList(OutputInterface $output, $players): void
    {
        $table = new Table($output);
        $playerRows = [];
        foreach ($players as $player) {
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

        $table
            ->setHeaders(['ID', 'Name', 'Level', 'Str', 'Sta', 'Dex', 'Fight Ability', 'Parry', 'Gold +', 'Gold -'])
            ->setRows($playerRows);

        $table->render();
    }
}
