<?php

declare(strict_types=1);

namespace Nawarian\Juja\Commands\Traits;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestFactoryInterface, RequestInterface, ResponseInterface, UriFactoryInterface};
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;
use Nawarian\KFStats\Entities\Player\PlayerRepository;

trait AuthenticationTrait
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private UriFactoryInterface $uriFactory;
    private PlayerRepository $playerRepository;

    private CookieJar $cookies;

    public function setCookies(CookieJarInterface $cookieJar): self
    {
        $this->cookies = $cookieJar;

        return $this;
    }

    private function login(OutputInterface $output): void
    {
        $output->writeln('Logging in.');
        $progressBar = new ProgressBar($output, 5);

        $progressBar->start();

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

        $progressBar->advance();

        // Fetch login form/csrf token
        $moonIdLoginPageRequest = $this->requestFactory->createRequest('GET', $loginUrl);
        $moonIdLoginPageResponse = $this->httpClient->sendRequest($moonIdLoginPageRequest);

        list (
            /** @var RequestInterface $moonIdLoginPageRequest */
            $moonIdLoginPageRequest,
            /** @var ResponseInterface $moonIdLoginPageResponse */
            $moonIdLoginPageResponse,
        ) = $this->followAllRedirects($moonIdLoginPageRequest, $moonIdLoginPageResponse);

        $progressBar->advance();

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

        $progressBar->advance();

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

        $progressBar->advance();

        // Follow last redirect to fetch KF_SERVER login token
        $tokenRequest = $this->requestFactory->createRequest('GET', $loginResponse->getHeader('location')[0]);
        $tokenResponse = $this->httpClient->sendRequest($tokenRequest);

        if ($tokenResponse->getStatusCode() !== 302) {
            throw new RuntimeException('Login failed 3.');
        }

        // Store session data
        $this->cookies->extractCookies($tokenRequest, $tokenResponse);

        $progressBar->finish();

        $output->writeln('');
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
