<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Middleware;

use GuzzleHttp\Psr7\Utils;
use Plugin\AzuracastShowDownloader\Service\Plugin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Appends the plugin's navigation script to AzuraCast's HTML panel pages.
 * The script adds the "Téléchargements auto" entry to the station sidebar and
 * registers the plugin page in AzuraCast's own Vue router.
 */
final readonly class InjectNavigation implements MiddlewareInterface
{
    private const string SCRIPT_ID = 'azuracast-show-downloader-navigation';

    public function __construct(
        private string $basePath = ''
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($request->getMethod() !== 'GET' || $response->getStatusCode() !== 200) {
            return $response;
        }

        $base = rtrim($this->basePath, '/');
        $path = $request->getUri()->getPath();
        if ($base !== '' && !str_starts_with($path, $base . '/')) {
            return $response;
        }
        $path = substr($path, strlen($base));

        if (
            !preg_match('~^/(?:dashboard|profile|admin|station)(?:/|$)~', $path)
            || !str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/html')
            || $response->hasHeader('Content-Encoding')
        ) {
            return $response;
        }

        $body = (string)$response->getBody();
        $position = strripos($body, '</body>');
        if ($position === false || str_contains($body, 'id="' . self::SCRIPT_ID . '"')) {
            return $response;
        }

        $src = htmlspecialchars(
            $base . '/show-downloader/assets/navigation.js?v=' . rawurlencode(Plugin::version()),
            ENT_QUOTES
        );
        $script = '<script type="module" id="' . self::SCRIPT_ID . '" src="' . $src . '"></script>';
        $body = substr_replace($body, $script, $position, 0);

        return $response->withBody(Utils::streamFor($body))
            ->withoutHeader('Content-Length')
            ->withoutHeader('ETag');
    }
}
