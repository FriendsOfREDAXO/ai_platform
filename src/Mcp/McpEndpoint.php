<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Mcp;

use FriendsOfRedaxo\Api\Auth\BearerAuth;
use FriendsOfRedaxo\Api\RouteCollection;
use FriendsOfRedaxo\Api\Token;
use Mcp\Server\Transport\Http\Middleware\PassthroughMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Core;
use Redaxo\Core\Filesystem\Url;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;

/**
 * Der MCP-Endpunkt `/api/mcp`, als Route des API-AddOns.
 *
 * Das API-AddOn bringt mit, was ein Endpunkt von außen braucht und was dieses AddOn sonst ein zweites Mal bauen
 * müsste: Tokens mit Ablaufdatum im Backend, Scopes, Routing vor yrewrite, CORS und Fehlerprotokoll. Ein Token
 * braucht den Scope `mcp`; er erscheint in der Token-Verwaltung, sobald diese Route registriert ist. Ohne API-AddOn
 * gibt es keinen MCP-Endpunkt, der Rest dieses AddOns arbeitet unverändert.
 *
 * Gesprochen wird Streamable HTTP über das offizielle PHP-SDK (`mcp/sdk`, PHP Foundation und Symfony). Eine Anfrage
 * ist eine PHP-Anfrage: Sitzungen der Handshake-Ära liegen in Dateien ({@see McpServer}), einen dauerhaft offenen
 * SSE-Kanal per GET gibt es nicht -- die Spezifikation erlaubt dafür 405, und das API-AddOn antwortet so.
 */
final class McpEndpoint
{
    /** Scope des Tokens und Name der Route. */
    public const string SCOPE = 'mcp';

    private static ?Token $token = null;

    public static function register(): void
    {
        if (!self::isAvailable()) {
            return;
        }

        RouteCollection::registerRoute(
            self::SCOPE,
            new Route('/mcp', ['_controller' => self::class . '::handle'], methods: ['POST', 'DELETE']),
            'MCP-Server (Model Context Protocol, Streamable HTTP) mit den Tools der AddOns und des Projekts',
            auth: new BearerAuth(),
            tags: ['mcp'],
        );
    }

    /** Ob das API-AddOn da ist, über das der Endpunkt läuft. */
    public static function isAvailable(): bool
    {
        return class_exists(RouteCollection::class) && Addon::exists('api');
    }

    /** Absolute Adresse des Endpunkts für die Konfiguration eines MCP-Clients. */
    public static function getUrl(): string
    {
        return rtrim(Url::absoluteBase(), '/') . '/' . RouteCollection::$preRoute . '/mcp';
    }

    /**
     * Das Token der laufenden Anfrage, für Tools, die je Aufrufer unterscheiden (Name, weitere Scopes). `null`
     * außerhalb einer MCP-Anfrage.
     */
    public static function getToken(): ?Token
    {
        return self::$token;
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $route
     */
    public static function handle(array $parameters, array $route): Response
    {
        $auth = $route['authorization'] ?? null;
        self::$token = $auth instanceof BearerAuth ? $auth->getAuthorizationObject() : null;

        $psr17 = new Psr17Factory();
        $request = new PsrHttpFactory($psr17, $psr17, $psr17, $psr17)->createRequest(Core::getRequest());

        // Herkunft, CORS und Anmeldung hat das API-AddOn schon geprüft; ohne Bearer-Token mit Scope kommt hier nichts an.
        $transport = new StreamableHttpTransport($request, $psr17, $psr17, middleware: [new PassthroughMiddleware()]);

        return self::toResponse(McpServer::build()->run($transport));
    }

    /**
     * Nicht über die HttpFoundation-Brücke: die machte aus einem Stream eine StreamedResponse, deren Inhalt das
     * API-AddOn beim Senden nicht mehr lesen kann.
     */
    private static function toResponse(ResponseInterface $response): Response
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return new Response((string) $response->getBody(), $response->getStatusCode(), $headers);
    }
}
