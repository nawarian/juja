<?php

declare(strict_types=1);

namespace Nawarian\KFStats\Commands;

use DOMElement;
use RuntimeException;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
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

final class LoadStats extends Command
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private UriFactoryInterface $uriFactory;

    private CookieJar $cookies;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        UriFactoryInterface $uriFactory
    ) {
        parent::__construct();

        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->uriFactory = $uriFactory;

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

            $crawler->filter('.highscore')->each(function (Crawler $player) use (&$players) {
                $name = $player->filter('td:nth-child(2) a')->text();

                $players[$name] = [
                    'name' => $name,
                    'url' => $player->filter('td:nth-child(2) a')->attr('href'),
                    'level' => $player->filter('td:nth-child(3)')->text(),
                ];
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

        foreach ($players as $player) {
            echo "{$player['name']} - Lv. {$player['level']} ({$player['url']})\n";
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
