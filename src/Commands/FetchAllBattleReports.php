<?php

declare(strict_types=1);

namespace Nawarian\Juja\Commands;

use DateTimeImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Nawarian\Juja\Commands\Traits\AuthenticationTrait;
use Nawarian\Juja\Entities\Battle\BattleReport;
use Nawarian\Juja\Entities\Battle\BattleReportRepository;
use Nawarian\Juja\Entities\Player\Player;
use Nawarian\Juja\Entities\Player\PlayerRepository;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;
use function GuzzleHttp\Psr7\parse_query;

final class FetchAllBattleReports extends Command
{
    use AuthenticationTrait;

    private BattleReportRepository $battleReportRepository;

    private Player $player;

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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->cookies->count() === 0) {
            $this->login($output);
        }

        $this->fetchCurrentPlayer();

        $battleReportAttacks = $this->createAuthenticatedRequest('GET', '/nachrichten/angriff/');

        $currentCount = 0;
        $total = null;
        do {
            $battleReportAttacks = $battleReportAttacks->withUri(
                $battleReportAttacks->getUri()->withQuery('count=' . $currentCount)
            );

            $battleReportAttacksResponse = $this->httpClient->sendRequest($battleReportAttacks);

            $crawler = new Crawler($battleReportAttacksResponse->getBody()->getContents());

            if (null === $total) {
                $rawTotalStr = $crawler->filter('.box-bg table:nth-child(2) tr:nth-child(2) .center')->text();
                preg_match('#[0-9]+ - [0-9]+ of ([0-9]+)#', $rawTotalStr, $matches);
                list (, $total) = $matches;
                $total = (int) $total;
            }

            $messagesTable = $crawler->filter('.box-bg table:nth-child(1) tr:nth-child(odd)');
            $messagesTable->each(function (Crawler $row) {
                $columns = $row->filter('td');

                if ($columns->first()->text() === 'Date:') {
                    return;
                }

                $victimUrl = $columns->eq(1)->filter('a')->attr('href');
                $victim = $this->playerRepository->fetchByUrl($victimUrl);

                $battleLink = $columns->last()->filter('a')->attr('href');
                $query = parse_url($battleLink, PHP_URL_QUERY);
                $battleId = (int) parse_query($query)['fightid'];
                $battleDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $columns->first()->text());
                $attackerId = $this->player->id;
                $victimId = $victim->id;

                $winnerRawStr = $columns->eq(2)->text();
                $winnerId = $this->player->name === $winnerRawStr ? $attackerId : $victimId;

                $report = new BattleReport();
                $report->battleId = $battleId;
                $report->attackerId = $attackerId;
                $report->victimId = $victimId;
                $report->winnerId = $winnerId;
                $report->date = $battleDate;

                $this->battleReportRepository->store($report);
            });

            $currentCount += 10;
        } while ($currentCount < $total);

        return 0;
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
