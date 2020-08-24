<?php

declare(strict_types=1);

namespace Nawarian\KFStats\Commands;

use DOMElement;
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
            ->withBody(Stream::create(
                $formEncodedData
            ));

        /** @var SetCookie $cookie */
        $cookies = [];
        foreach ($moonIdCookieJar as $cookie) {
            $cookieName = $cookie->getName();
            $cookies[] = str_replace("{$cookieName}=", '', $cookie->getValue());
        }
        $loginRequest = $loginRequest->withHeader('cookie', $cookies);

        echo(
            $this->httpClient->sendRequest($loginRequest)
                ->getBody()->getContents()
        ); die;

        // Store session data
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

            $request = $request->withUri($nextUri->withQuery($query));
            $response = $this->httpClient->sendRequest($request);
        } while (true);

        return [$request, $response];
    }
}
