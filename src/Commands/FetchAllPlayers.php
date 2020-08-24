<?php

declare(strict_types=1);

namespace Nawarian\KFStats\Commands;

use DateTimeImmutable;
use Nawarian\KFStats\Entities\Player\Player;
use Nawarian\KFStats\Entities\Player\PlayerRepository;
use RuntimeException;
use GuzzleHttp\Cookie\CookieJar;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

final class FetchAllPlayers extends Command
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private UriFactoryInterface $uriFactory;
    private PlayerRepository $playerRepository;

    private CookieJar $cookies;

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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Loading stats from KF.');

        $serverAddress = getenv('KF_SERVER');
        $account = getenv('KF_ACCOUNT');
        $password = getenv('KF_PASSWORD');

        if (!$serverAddress || !$account || !$password) {
            $output->writeln('FATAL ERROR: check the .env file.');
        }

        $this->login();

        $csrf = function (string $responseBody) : string {
            $crawler = new Crawler($responseBody);

            return $crawler->filter('input[name=csrftoken]')->attr('value');
        };

        // The first request fetches the first 100 items and the CSRF token
        $highScoreRequest = $this->createAuthenticatedRequest('GET', '/highscore/');
        $players = [];
        $count = 100;
        do {
            $highScoreResponse = $this->httpClient->sendRequest($highScoreRequest);
            $highScoreResponseBody = $highScoreResponse->getBody()->getContents();
            $crawler = new Crawler($highScoreResponseBody);

            // .highscore always has at least one, which is the current account
            $hasPlayers = $crawler->filter('.highscore')->count() > 1;
            if ($hasPlayers === false) {
                break;
            }

            $crawler->filter('.highscore')->each(function (Crawler $playerCrawler) use (&$players) {
                $name = $playerCrawler->filter('td:nth-child(2) a')->text();

                $player = new Player();
                $player->name = $name;
                $player->url = $playerCrawler->filter('td:nth-child(2) a')->attr('href');
                $player->level = (int) $playerCrawler->filter('td:nth-child(3)')->text();

                $players[$name] = $player;
            });

            $count += 100;
            $highScoreRequest = $this->createAuthenticatedRequest('POST', '?fragment=1')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody(Stream::create(http_build_query([
                    'ac' => 'highscore',
                    'sac' => 'spieler',
                    'sort' => 1,
                    'csort' => 1,
                    'filter' => 'beute',
                    'clanfilter' => 'beute',
                    'csrftoken' => $csrf($highScoreResponseBody),
                    'count' => $count,
                ])));
        } while (true);

        // Fetch all players' information
        $fetchStatNumber = function (Crawler $sword) {
            $numbers = [];
            foreach ($sword->filter('div[class$="elem"] > img') as $image) {
                $number = $image->getAttribute('src');
                $number = trim(pathinfo($number, PATHINFO_FILENAME), 'b');

                $numbers[] = $number;
            }

            return (int) implode('', $numbers);
        };

        /** @var Player $player */
        foreach ($players as $player) {
            $playerStatsRequest = $this->createAuthenticatedRequest(
                'GET',
                parse_url($player->url, PHP_URL_PATH),
            );

            $playerStatsResponse = $this->httpClient->sendRequest($playerStatsRequest);

            $crawler = new Crawler($playerStatsResponse->getBody()->getContents());

            // Health points: %02f of %d<br>%d percent healing per hour
            $healthPoints = $crawler->filter('.img-showuser-life~a')->attr('rel');
            preg_match('#Health points: ([0-9,]+\.[0-9]+) of ([0-9]+)#', $healthPoints, $matches);
            list ($healthPointsStr, $currentHP, $maxHP) = $matches;

            // Experience: %d of %d
            $expPoints = $crawler->filter('.img-showuser-xp~a')->attr('rel');
            preg_match('#Experience: ([0-9,]+) of ([0-9]+)#', $expPoints, $matches);
            list ($expPointsStr, $currentExp, $maxExp) = $matches;

            // Player properties
            $player->id = (int) $crawler->filter('.tooltip[rel~="Level:"]')->text();
            $player->currentHP = (float) $currentHP;
            $player->maxHP = (int) $maxHP;
            $player->experience = (int) $currentExp;

            $alignment = $crawler->filter('.tooltip[rel~="Alignment:"]')->attr('rel');
            preg_match('#~(\-?[0-9,]+)#', $alignment, $matches);
            if (count($matches) === 0) {
                var_dump($alignment, $player->url); die;
            }
            list ($alignmentStr, $alignment) = $matches;

            $player->alignment = (int) $alignment;

            // Attributes & Skills
            $stats = $crawler->filter('.sc');

            $player->strength = $fetchStatNumber($stats->eq(0));
            $player->stamina = $fetchStatNumber($stats->eq(1));
            $player->dexterity = $fetchStatNumber($stats->eq(2));
            $player->fightingAbility = $fetchStatNumber($stats->eq(3));
            $player->parry = $fetchStatNumber($stats->eq(4));

            $player->armour = $fetchStatNumber($stats->eq(5));
            $player->oneHandedAttack = $fetchStatNumber($stats->eq(6));
            $player->twoHandedAttack = $fetchStatNumber($stats->eq(7));

            $crawler->filter('.box-bg-profil table:nth-child(1) tr')->each(function (Crawler $row) use ($player) {
                if ($row->filter('td')->eq(0)->text() === 'Knight since:') {
                    $player->createdAt = DateTimeImmutable::createFromFormat(
                        'Y-m-d H:i:s',
                        $row->filter('td')->eq(1)->text(),
                    );
                }
            });

            $statistics = $crawler->filter('.box-bg-profil table:nth-child(2) tr');
            $player->totalLoot = (int) str_replace(',', '', $statistics->eq(2)->filter('td:nth-child(2)')->text());
            $player->totalBattles = (int) $statistics->eq(4)->filter('td:nth-child(2)')->text();
            $player->wins = (int) $statistics->eq(5)->filter('td:nth-child(2)')->text();
            $player->losses = (int) $statistics->eq(6)->filter('td:nth-child(2)')->text();
            $player->undecided = (int) $statistics->eq(7)->filter('td:nth-child(2)')->text();
            $player->goldReceived = (int) str_replace(',', '', $statistics->eq(8)->filter('td:nth-child(2)')->text());
            $player->goldLost = (int) str_replace(',', '', $statistics->eq(9)->filter('td:nth-child(2)')->text());
            $player->damageToEnemies = (int) $statistics->eq(10)->filter('td:nth-child(2)')->text();
            $player->damageFromEnemies = (int) $statistics->eq(11)->filter('td:nth-child(2)')->text();

             $this->playerRepository->store($player);
        }

        return 0;
    }

    private function login(): void
    {
        // Get login url
        $uri = $this->uriFactory->createUri(getenv('KF_SERVER'));
        $homeRequest = $this->requestFactory->createRequest('GET', $uri);
        $homeResponse = $this->httpClient->sendRequest($homeRequest);

        $home = new Crawler($homeResponse->getBody()->getContents());
        $this->cookies->extractCookies($homeRequest, $homeResponse);

        $loginUrl = '';
        foreach ($home->filter('.moonid-button') as $link) {
            if ($link->textContent === 'Login') {
                $loginUrl = $link->getAttribute('href');
            }
        }

        // Fetch login form/csrf token
        $moonIdLoginPageRequest = $this->requestFactory->createRequest('GET', $loginUrl);
        $moonIdLoginPageResponse = $this->httpClient->sendRequest($moonIdLoginPageRequest);

        list (
            /** @var RequestInterface $moonIdLoginPageRequest */
            $moonIdLoginPageRequest,
            /** @var ResponseInterface $moonIdLoginPageResponse */
            $moonIdLoginPageResponse,
        ) = $this->followAllRedirects($moonIdLoginPageRequest, $moonIdLoginPageResponse);

        $moonIdCookieJar = new CookieJar();
        $moonIdCookieJar->extractCookies($moonIdLoginPageRequest, $moonIdLoginPageResponse);

        $loginForm = new Crawler(
            $moonIdLoginPageResponse->getBody()->getContents(),
            (string) $moonIdLoginPageRequest->getUri()->withPath(''),
        );

        $form = $loginForm->filter('form[action="/account/login/"]')->form([
            'username' => getenv('KF_ACCOUNT'),
            'password' => getenv('KF_PASSWORD'),
        ]);

        $formEncodedData = [];
        foreach ($form->getPhpValues() as $field => $value) {
            $value = urlencode($value);
            $formEncodedData[] = "{$field}={$value}";
        }
        $formEncodedData = implode('&', $formEncodedData);

        // Attempt to login
        $loginRequest = $moonIdLoginPageRequest
            ->withMethod($form->getMethod())
            ->withUri(
                $this->uriFactory->createUri($form->getUri())
            )->withHeader('Referer', (string) $moonIdLoginPageRequest->getUri())
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody(Stream::create($formEncodedData));

        $loginRequest = $moonIdCookieJar->withCookieHeader($loginRequest);
        $loginResponse = $this->httpClient->sendRequest($loginRequest);

        if ($loginResponse->getStatusCode() !== 302) {
            throw new RuntimeException('Login failed.');
        }
        
        // Follow first redirect to /api/account/connect/{server}/
        $moonIdCookieJar = new CookieJar();
        $moonIdCookieJar->extractCookies($loginRequest, $loginResponse);

        $loginRequest = $loginRequest->withUri(
            $loginRequest->getUri()->withPath($loginResponse->getHeader('location')[0])
        )->withMethod('GET')
        ->withoutHeader('referer')
        ->withoutHeader('host')
        ->withoutHeader('content-type');

        $loginRequest = $moonIdCookieJar->withCookieHeader($loginRequest);
        $loginResponse = $this->httpClient->sendRequest($loginRequest);

        if ($loginResponse->getStatusCode() !== 302 || str_contains($loginResponse->getHeader('location')[0], getenv('KF_SERVER')) === false) {
            throw new RuntimeException('Login failed 2.');
        }

        // Follow last redirect to fetch KF_SERVER login token
        $tokenRequest = $this->requestFactory->createRequest('GET', $loginResponse->getHeader('location')[0]);
        $tokenResponse = $this->httpClient->sendRequest($tokenRequest);

        if ($tokenResponse->getStatusCode() !== 302) {
            throw new RuntimeException('Login failed 3.');
        }

        // Store session data
        $this->cookies->extractCookies($tokenRequest, $tokenResponse);
    }

    private function followAllRedirects(RequestInterface $request, ResponseInterface $response): array
    {
        do {
            $location = $response->getHeader('location');

            // No other redirects
            if ($location === []) {
                break;
            }

            $location = array_shift($location);
            $query = '';
            if (str_contains($location, '?') === true) {
                list ($location, $query) = explode('?', $location);
            }

            $nextUri = $request->getUri();
            if (str_starts_with($location, '/') === false) {
                $nextUri = $this->uriFactory->createUri($location);
            } else {
                $nextUri = $nextUri->withPath($location);
            }

            $request = $request->withUri($nextUri->withQuery($query))->withMethod('GET');
            $response = $this->httpClient->sendRequest($request);
        } while (true);

        return [$request, $response];
    }

    private function createAuthenticatedRequest(string $method, string $path): RequestInterface
    {
        $serverAddress = trim(getenv('KF_SERVER'), '/');
        $uri = $this->uriFactory->createUri("{$serverAddress}/{$path}");

        return $this->cookies->withCookieHeader(
            $this->requestFactory->createRequest($method, $uri),
        );
    }
}
