<?php

declare(strict_types=1);

namespace Nawarian\Juja\Commands;

use GuzzleHttp\Cookie\CookieJar;
use Nawarian\Juja\Commands\Traits\{AuthenticationTrait, ClearScreenTrait};
use Nawarian\Juja\Entities\Battle\BattleReportRepository;
use Nawarian\Juja\Entities\Player\PlayerRepository;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestFactoryInterface, UriFactoryInterface};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\DomCrawler\Crawler;

final class GameConsole extends Command
{
    use AuthenticationTrait;
    use ClearScreenTrait;

    private BattleReportRepository $battleReportRepository;

    private string $nextAction = '';

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        UriFactoryInterface $uriFactory,
        PlayerRepository $playerRepository,
        BattleReportRepository $battleReportRepository
    ) {
        parent::__construct();

        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->uriFactory = $uriFactory;
        $this->playerRepository = $playerRepository;
        $this->battleReportRepository = $battleReportRepository;

        $this->cookies = new CookieJar();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $questionHelper = new QuestionHelper();
        $output->writeln('Welcome to the KF Game Console!');

        $loginQuestion = new ConfirmationQuestion('Before starting we need to log in. Shall we do it now? [Y/n] ', true);
        $loginAllowed = $questionHelper->ask($input, $output, $loginQuestion);

        if (false === $loginAllowed) {
            $output->writeln('Exiting...');
            return 0;
        }

        $this->login($output);

        $output->writeln("We're in! Let's find out who you are.");
        try {
            $this->fetchCurrentPlayer();
        } catch (\Throwable $e) {
            $this->updateDatabase($input, $output, $questionHelper);
            $this->fetchCurrentPlayer();
        }

        $exitCode = 0;
        while ($this->nextAction !== 'quit' && $exitCode === 0) {
            $exitCode = $this->mainMenu($input, $output, $questionHelper);
        }

        return $exitCode;
    }

    private function mainMenu(InputInterface $input, OutputInterface $output, QuestionHelper $questionHelper): int
    {
        $this->clearScreen($input, $output);

        $menu = new ChoiceQuestion(
            'What would you like to do?',
            [
                'attack' => 'Find and attack other players',
                'mission' => 'Mission',
                'work' => 'Work on the Tavern',
                'merch' => 'Shop w/ Merchant',
                'bazaar' => 'Shop in Bazaar',
                'gamble' => 'Gamble in Bazaar',

                'update' => 'Update whole database (fetch from highscore)',
            ],
        );

        switch ($questionHelper->ask($input, $output, $menu)) {
            case 'attack':
                $this->clearScreen($input, $output);
                $this->attack($input, $output);
                return 0;
            case 'update':
                $this->updateDatabase($input, $output, $questionHelper);
                return 0;
        }

        return 0;
    }

    private function updateDatabase(InputInterface $input, OutputInterface $output, QuestionHelper $questionHelper): void
    {
        $command = new FetchAllPlayers(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->playerRepository,
        );

        // Use same cookies as this session's
        $command->setCookies($this->cookies);

        $command->run($input, $output);

        $command = new FetchAllBattleReports(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->playerRepository,
            $this->battleReportRepository,
        );

        // Use same cookies as this session's
        $command->setCookies($this->cookies);

        $command->run($input, $output);
    }

    private function attack(InputInterface $input, OutputInterface $output): void
    {
        $command = new Attack(
            $this->playerRepository,
            $this->player,
        );

        // Use same cookies as this session's
        $command->setCookies($this->cookies);

        $command->run($input, $output);
    }

    private function fetchCurrentPlayer(): void
    {
        $statusPage = $this->httpClient->sendRequest($this->createAuthenticatedRequest('GET', '/status/'));
        $crawler = new Crawler($statusPage->getBody()->getContents());

        preg_match('#([0-9]+)#', $crawler->filter('.your_id')->text(), $matches);

        list ($idString, $playerId) = $matches;

        $this->player = $this->playerRepository->fetchById((int) $playerId);
    }
}
