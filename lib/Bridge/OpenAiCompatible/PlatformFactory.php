<?php

namespace FriendsOfRedaxo\AiPlatform\Bridge\OpenAiCompatible;

use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Platform;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gleiches Muster wie Symfony AI's eigene Bridge-Factories
 * (Ollama\PlatformFactory u.a.) -- hier aber im eigenen Addon-Code statt
 * als Symfony-AI-Paket, da es kein offizielles "generisches OpenAI-
 * kompatibel"-Bridge-Paket gibt (Symfony AI's eigener OpenAI-Bridge ist
 * fest auf api.openai.com + die neuere Responses API verdrahtet, kein
 * Basis-URL-Override moeglich). Contract::create() OHNE zusaetzliche
 * Normalizer (siehe ModelClient-Docblock) -- die Basis-Normalizer
 * produzieren bereits das klassische Chat-Completions-Format.
 */
final class PlatformFactory
{
    public static function create(
        string $baseUrl,
        #[\SensitiveParameter] ?string $apiKey = null,
        ?HttpClientInterface $httpClient = null,
    ): Platform {
        $httpClient = $httpClient instanceof EventSourceHttpClient ? $httpClient : new EventSourceHttpClient($httpClient);

        return new Platform(
            [new ModelClient($httpClient, $apiKey, $baseUrl)],
            [new ResultConverter()],
            new ModelCatalog(),
            Contract::create(),
        );
    }
}
