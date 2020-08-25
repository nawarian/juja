<?php

declare(strict_types=1);

namespace Nawarian\KFStats\Commands;

use GuzzleHttp\Cookie\CookieJar;
use Nawarian\KFStats\Entities\Player\{Player, PlayerRepository};
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\DomCrawler\Crawler;

final class GameConsole extends Command
{
    use AuthenticationTrait;

    private Player $player;

    private string $nextAction = '';

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        UriFactoryInterface $uriFactory,
        PlayerRepository $playerRepository
    ) {
        parent::__construct();

        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->uriFactory = $uriFactory;
        $this->playerRepository = $playerRepository;

        $this->cookies = new CookieJar();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $questionHelper = new QuestionHelper();
        $output->writeln('Welcome to the KF Game Console!');

        $loginQuestion = new ConfirmationQuestion('Before starting we need to log in. Shall we do it now? [y/N] ', false);
        $loginAllowed = $questionHelper->ask($input, $output, $loginQuestion);

        if (false === $loginAllowed) {
            $output->writeln('Exiting...');
            return 0;
        }

        $this->login($output);

        $output->writeln("We're in! Let's find out who you are.");

        $this->fetchCurrentPlayer($input, $output);

        $exitCode = 0;
        while ($this->nextAction !== 'quit' && $exitCode === 0) {
            $exitCode = $this->mainMenu($input, $output, $questionHelper);
        }

        return $exitCode;
    }

    private function fetchCurrentPlayer(InputInterface $input, OutputInterface $output): void
    {
        $progressBar = new ProgressBar($output);

        $progressBar->start();

        $progressBar->advance();
        $statusPage = $this->httpClient->sendRequest($this->createAuthenticatedRequest('GET', '/status/'));
        $progressBar->advance();
        $crawler = new Crawler($statusPage->getBody()->getContents());

        preg_match('#([0-9]+)#', $crawler->filter('.your_id')->text(), $matches);

        list ($idString, $playerId) = $matches;
        $progressBar->advance();

        $this->player = $this->playerRepository->fetchById((int) $playerId);

        $progressBar->finish();
        $output->writeln('');
    }

    private function mainMenu(InputInterface $input, OutputInterface $output, QuestionHelper $questionHelper): int
    {
        $menu = new ChoiceQuestion(
            'What would you like to do?',
            [
                'find' => 'Find enemies',
                'mission' => 'Mission',
                'work' => 'Work on the Tavern',
                'merch' => 'Shop w/ Merchant',
                'bazaar' => 'Shop in Bazaar',
                'gamble' => 'Gamble in Bazaar',
            ],
        );

        switch ($questionHelper->ask($input, $output, $menu)) {
            case 'find':
                $this->findPlayer($input, $output, $questionHelper);
                return 0;
        }

        return 0;
    }

    private function findPlayer(InputInterface $input, OutputInterface $output, QuestionHelper $questionHelper): void
    {
        $choices = new ChoiceQuestion(
            'What are you looking for?',
            [
                'farm' => 'Find weaker players that will potentially yield good money',
                'lvlup' => 'Find weaker players of higher level',
                'lvldown' => 'Find weaker players of lower level so you lose experience',
            ],
        );

        switch ($questionHelper->ask($input, $output, $choices)) {
            case 'farm':
                $this->farm($input, $output, $questionHelper, 0);
                break;
        }

        $output->writeln('');
    }

    private function farm(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        int $offset
    ): void {
        $table = new Table($output);

        $playerRows = [];
        $players = $this->playerRepository->fetchPlayersWeakerThan($this->player, 10, $offset * 10);
        foreach ($players as $player) {
            $playerRows[] = [
                $player->name,
                $player->level,
                $player->strength,
                $player->stamina,
                $player->dexterity,
                $player->fightingAbility,
                $player->parry,
            ];
        }

        $table
            ->setHeaders(['Name', 'Level', 'Str', 'Sta', 'Dex', 'Fight Ability', 'Parry'])
            ->setRows($playerRows);

        $table->render();

        $choices = new ChoiceQuestion(
            'Load more or attack?',
            [
                'load more' => 'Load more...',
                'attack' => 'Attack a player',
                'cancel' => 'Cancel, go back to main menu',
            ],
        );

        switch ($questionHelper->ask($input, $output, $choices)) {
            case 'load more':
                $this->farm($input, $output, $questionHelper, ++$offset);
                break;
            case 'cancel':
                $this->nextAction = 'main menu';
                break;
            default:
                break;
        }
    }
}
