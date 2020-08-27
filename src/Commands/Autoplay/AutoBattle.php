<?php

declare(strict_types=1);

namespace Nawarian\Juja\Commands\Autoplay;

use GuzzleHttp\Cookie\CookieJar;
use Nawarian\Juja\Commands\FetchAllBattleReports;
use Nawarian\Juja\Commands\Traits\AuthenticationTrait;
use Nawarian\Juja\Entities\Battle\BattleReportRepository;
use Nawarian\Juja\Entities\Player\Player;
use Nawarian\Juja\Entities\Player\PlayerRepository;
use Nawarian\Juja\Services\PlayerLock;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;

final class AutoBattle extends Command
{
    use AuthenticationTrait;

    private Player $player;

    private PlayerLock $playerLockService;

    private BattleReportRepository $battleReportRepository;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        UriFactoryInterface $uriFactory,
        PlayerRepository $playerRepository,
        PlayerLock $playerLockService,
        BattleReportRepository $battleReportRepository
    ) {
        parent::__construct('AutoBattle');

        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->uriFactory = $uriFactory;
        $this->playerRepository = $playerRepository;
        $this->playerLockService = $playerLockService;
        $this->battleReportRepository = $battleReportRepository;

        $this->cookies = new CookieJar();
        $this->playerLockService->setCookies($this->cookies);
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $style->text('Starting autoplay:autobattle mode.');

        $this->fetchCurrentPlayer();

        $emptyQueueFailedAttempts = 0;
        while (true) {
            $lockInSeconds = $this->playerLockService->getPlayerBattleLockInSeconds();
            if ($lockInSeconds > 0) {
                $style->warning("Locked for {$lockInSeconds} seconds. Waiting...");

                $progress = $style->createProgressBar($lockInSeconds);
                $progress->display();
                while ($lockInSeconds > 0) {
                    $progress->advance(1);

                    sleep(1);
                    $lockInSeconds--;
                }
                $progress->finish();
            } else if ($lockInSeconds === 0) {
                $nextVictimUrl = $this->dequeueFromAttackQueue();

                if (null === $nextVictimUrl) {
                    $style->warning('Attack queue is empty! Place URLs into the "attack.queue" file.');
                    sleep(2);

                    if ($emptyQueueFailedAttempts < 10) {
                        $emptyQueueFailedAttempts++;
                        continue;
                    }
                }

                $victim = $this->playerRepository->fetchByUrl($nextVictimUrl);
                $canAttack = false === $this->playerLockService->wasPlayerAttackedLastNHours(
                    $this->player,
                    $victim,
                    12
                );

                if (false === $canAttack) {
                    $style->comment("The player {$victim->name} was already attacked within last 12 hours.");
                    continue;
                }

                $this->attack($victim, $style);

                sleep(2);
                continue;
            }
        }

        return 0;
    }

    private function dequeueFromAttackQueue(): ?string
    {
        $urls = array_filter(explode(PHP_EOL, file_get_contents(__DIR__ . '/../../../attack.queue')));
        $nextUrl = array_shift($urls);

        file_put_contents(__DIR__ . '/../../../attack.queue', implode(PHP_EOL, $urls));

        return $nextUrl;
    }

    private function attack(Player $victim, SymfonyStyle $style): void
    {
        $style->note("Attacking a player: {$victim->name}");

        $attackPageRequest = $this->createAuthenticatedRequest('GET', '/raubzug/gegner/');
        $attackPageRequest = $attackPageRequest->withUri(
            $attackPageRequest->getUri()->withQuery('searchuserid=' . $victim->id),
        );

        $attackPageResponse = $this->httpClient->sendRequest($attackPageRequest);
        $crawler = new Crawler($attackPageResponse->getBody()->getContents());

        $form = $crawler->filter('form')->form();
        $formEncodedData = [];
        foreach ($form->getPhpValues() as $field => $value) {
            $value = urlencode($value);
            $formEncodedData[] = "{$field}={$value}";
        }
        $formEncodedData = implode('&', $formEncodedData);

        $this->httpClient->sendRequest(
            $this->createAuthenticatedRequest($form->getMethod(), '/')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody(Stream::create($formEncodedData))
        );

        $command = new FetchAllBattleReports(
            $this->httpClient,
            $this->requestFactory,
            $this->uriFactory,
            $this->playerRepository,
            $this->battleReportRepository,
        );

        // Use same cookies as this session's
        $command->setCookies($this->cookies);

        $command->run(new ArgvInput([]), new NullOutput());
    }

    private function fetchCurrentPlayer(): void
    {
        $statusPage = $this->httpClient->sendRequest($this->createAuthenticatedRequest('GET', '/status/'));
        $crawler = new Crawler($statusPage->getBody()->getContents());

        preg_match('#([0-9]+)#', $crawler->filter('.your_id')->text(), $matches);

        list (, $playerId) = $matches;

        $this->player = $this->playerRepository->fetchById((int) $playerId);
    }
}
