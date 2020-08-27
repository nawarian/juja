<?php

declare(strict_types=1);

namespace Nawarian\Juja\Services;

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

final class PlayerLock
{
    use AuthenticationTrait;

    private BattleReportRepository $battleHistoryRepository;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        UriFactoryInterface $uriFactory,
        PlayerRepository $playerRepository,
        BattleReportRepository $battleHistoryRepository
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->uriFactory = $uriFactory;
        $this->playerRepository = $playerRepository;
        $this->battleHistoryRepository = $battleHistoryRepository;

        $this->cookies = new CookieJar();
    }

    public function getPlayerBattleLockInSeconds(): int
    {
        $missionPage = $this->createAuthenticatedRequest('GET', '/raubzug/');
        $missionPageResponse = $this->httpClient->sendRequest($missionPage);
        $rawHTML = $missionPageResponse->getBody()->getContents();

        // Page has a `var Secondscounter` containing the seconds this player is locked
        if (false === preg_match('#var Secondscounter = ([0-9]+);#', $rawHTML, $matches)) {
            return 0;
        }

        return (int) ($matches[1] ?? 0);
    }

    public function wasPlayerAttackedLastNHours(Player $attacker, Player $victim, int $hours): bool
    {
        /** @var BattleReport[] $battles */
        $battles = $this->battleHistoryRepository->findByAttackerIdAndVictimId($attacker->id, $victim->id);

        $now = new DateTimeImmutable('now');

        foreach ($battles as $battle) {
            $diff = $battle->date->diff($now);

            if (0 === $diff->days && $diff->h < $hours) {
                return true;
            }
        }

        return false;
    }
}
