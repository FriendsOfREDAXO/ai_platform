<?php

/**
 * The provider registry and the payload the profile form is built from.
 *
 * Two things are worth knowing about the shape of this file. It runs against the
 * real REDAXO boot (see bootstrap.php) rather than stubbing rex_i18n, because a
 * provider label that comes out as "[translate:…]" is a bug this suite should
 * catch. And it drives the bridges through a MockHttpClient instead of a live
 * endpoint: the request that goes out on the wire is the thing under test, and
 * asserting it against a mock needs neither a network nor an API key.
 *
 * Reads nothing from the database and writes nothing anywhere.
 */

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\ProviderRegistry;
use FriendsOfRedaxo\AiPlatform\Service;
use Symfony\AI\Platform\Bridge\Generic\FallbackModelCatalog;
use Symfony\AI\Platform\Bridge\Generic\PlatformFactory as GenericFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require __DIR__ . '/bootstrap.php';

$t = new AiTestRunner('Provider registry');

/**
 * The profile types, i.e. the keys every provider's model list carries -- sorted, because
 * the order of TYPE_CAPABILITIES is an implementation detail nothing should depend on.
 */
$types = ['embedding', 'image_generation', 'image_understanding', 'text'];

/**
 * Sends one request through the real bridge against a mock and returns what went
 * out on the wire.
 *
 * @param array<string, mixed> $profile
 * @param array<string, mixed> $response
 *
 * @return array{method: string, url: string, headers: list<string>}
 */
$capture = static function (array $profile, array $response): array {
    $captured = null;
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured, $response): MockResponse {
        $captured = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

        return new MockResponse(json_encode($response, JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    });

    // The model name matters: a catalog that does not know it refuses the call before a
    // request is even built (see the strict-catalog assertions below).
    ProviderRegistry::createPlatform($profile, $client)
        ->invoke((string) ($profile['model'] ?? 'some-model'), new MessageBag(Message::ofUser('ping')))
        ->asText();

    if (!is_array($captured)) {
        throw new RuntimeException('no request was sent');
    }

    return $captured;
};

/**
 * The Authorization header of a captured request, or null when there is none.
 *
 * Symfony writes the header capitalised ("Authorization: Bearer …"). Matching it in
 * lower case finds nothing — which passes the "no header" assertion for the wrong
 * reason and fails the "header is there" one. Both have to look the same way.
 *
 * @param list<string> $headers
 */
$authHeader = static function (array $headers): ?string {
    foreach ($headers as $header) {
        if (str_starts_with(strtolower($header), 'authorization:')) {
            return trim(substr($header, strlen('authorization:')));
        }
    }

    return null;
};

/** A chat-completions answer the generic bridge's converter accepts. */
$completion = ['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]];

// ---------------------------------------------------------------------------
$t->section('Registered providers');

$t->assertSame(
    ['openai', 'anthropic', 'google', 'ollama', 'openrouter', 'replicate', 'generic'],
    array_keys(ProviderRegistry::all()),
    'the shipped providers are registered, in order',
);

foreach (ProviderRegistry::labels() as $key => $label) {
    $t->assert('' !== $label && !str_contains($label, 'translate:'), $key . ' has a translated label (' . $label . ')');
}

$knownFields = array_merge(ProviderRegistry::CREDENTIAL_FIELDS, ProviderRegistry::OPTION_FIELDS);
foreach (ProviderRegistry::all() as $key => $definition) {
    $t->assert([] !== $definition['fields'], $key . ' declares the fields it needs');
    $t->assert(
        [] === array_diff($definition['fields'], $knownFields),
        $key . ' declares only fields the form knows',
    );
}

// The regression this replaces: the API-key field stayed hidden for Ollama long
// after getPlatform() had started passing the key on.
$t->assert(in_array('api_key', ProviderRegistry::get('ollama')['fields'], true), 'ollama asks for an api key');
$t->assert(in_array('base_url', ProviderRegistry::get('ollama')['fields'], true), 'ollama asks for a base url');
$t->assert(in_array('base_url', ProviderRegistry::get('generic')['fields'], true), 'generic asks for a base url');
$t->assert(!in_array('base_url', ProviderRegistry::get('anthropic')['fields'], true), 'anthropic does not ask for a base url');
$t->assert(
    in_array('image_quality', ProviderRegistry::get('openai')['fields'], true)
    && !in_array('image_quality', ProviderRegistry::get('google')['fields'], true),
    'the DALL-E options belong to openai only',
);

$t->assertThrows(
    static fn () => ProviderRegistry::get('does-not-exist'),
    'an unknown provider throws',
    'Unknown AI provider',
);

// ---------------------------------------------------------------------------
$t->section('Model lists come from the bridge catalogs');

$text = ProviderRegistry::models('openai', 'text');
$t->assert(in_array('gpt-4o', $text, true), 'openai/text offers gpt-4o');
$t->assert(!in_array('text-embedding-3-small', $text, true), 'openai/text leaves out embedding models');
$t->assert(!in_array('dall-e-3', $text, true), 'openai/text leaves out image models');
// OUTPUT_TEXT alone also matches speech-to-text, which is why TYPE_CAPABILITIES
// demands INPUT_MESSAGES next to it.
$t->assert(!in_array('whisper-1', $text, true), 'openai/text leaves out whisper-1');

$embedding = ProviderRegistry::models('openai', 'embedding');
$t->assert(in_array('text-embedding-3-small', $embedding, true), 'openai/embedding offers text-embedding-3-small');
$t->assert(!in_array('gpt-4o', $embedding, true), 'openai/embedding leaves out chat models');
$t->assert(in_array('dall-e-3', ProviderRegistry::models('openai', 'image_generation'), true), 'openai/image_generation offers dall-e-3');

$t->assertSame([], ProviderRegistry::models('anthropic', 'image_generation'), 'anthropic offers no image generation');
$t->assertSame([], ProviderRegistry::models('anthropic', 'embedding'), 'anthropic offers no embeddings');

foreach ($types as $type) {
    $t->assertSame([], ProviderRegistry::models('generic', $type), 'generic has no catalog for ' . $type);
}
$t->assert(
    (ProviderRegistry::get('generic')['catalog'])() instanceof FallbackModelCatalog,
    'generic uses the fallback catalog, which accepts any model name',
);

$sorted = $text;
sort($sorted);
$t->assertSame($sorted, $text, 'model lists are sorted');
$t->assertSame(array_values(array_unique($text)), $text, 'model lists have no duplicates');

// OpenRouter's catalog describes its entries with INPUT_TEXT rather than INPUT_MESSAGES.
// Demanding INPUT_MESSAGES left 2 of 362 models standing, which is why TYPE_CAPABILITIES
// accepts either -- a plain count assertion here would break with every catalog update, so
// this asserts the order of magnitude and one known name instead.
$openRouterText = ProviderRegistry::models('openrouter', 'text');
$t->assert(count($openRouterText) > 100, 'openrouter offers its catalog for text (' . count($openRouterText) . ' models)');
$t->assert(in_array('openai/gpt-4o', $openRouterText, true), 'openrouter/text offers openai/gpt-4o');
$t->assert([] !== ProviderRegistry::models('openrouter', 'image_understanding'), 'openrouter offers vision models');
$t->assert([] !== ProviderRegistry::models('openrouter', 'embedding'), 'openrouter offers embedding models');

// Symfony AI's Replicate bridge is a Llama text client -- LlamaModelClient,
// LlamaResultConverter, a catalog of llama-* entries. None of the image models Replicate
// is otherwise known for are reachable through it, and the label says so.
$t->assert([] !== ProviderRegistry::models('replicate', 'text'), 'replicate offers llama text models');
foreach (['image_generation', 'image_understanding', 'embedding'] as $type) {
    $t->assertSame([], ProviderRegistry::models('replicate', $type), 'replicate offers nothing for ' . $type);
}

$t->assertSame([], ProviderRegistry::models('openai', 'no-such-type'), 'an unknown type yields no models');
$t->assertSame([], ProviderRegistry::models('no-such-provider', 'text'), 'an unknown provider yields no models');

// ---------------------------------------------------------------------------
$t->section('Defaults must be pickable');

// A default the catalog does not list drops the form onto "custom model name" the
// moment the provider is selected. That is how the stale gemini-2.0-flash-exp and
// text-embedding-004 defaults surfaced.
foreach (ProviderRegistry::all() as $key => $definition) {
    foreach ($definition['defaults'] as $type => $default) {
        $models = ProviderRegistry::models($key, $type);
        if ([] === $models) {
            // No catalog for this pairing: the default pre-fills the free-text input.
            $t->assert('' !== $default, $key . '/' . $type . ' pre-fills a name although it has no catalog');
            continue;
        }

        $t->assert(
            in_array($default, $models, true),
            sprintf('%s/%s default "%s" is offered by the catalog', $key, $type, $default),
        );
    }

    foreach (array_keys($definition['defaults']) as $type) {
        $t->assert(in_array($type, $types, true), $key . ' has no default for an unknown type');
    }
}

// ---------------------------------------------------------------------------
$t->section('Payload for the profile form');

$config = ProviderRegistry::formConfig();
$t->assertSame(array_keys(ProviderRegistry::all()), array_keys($config), 'every provider is in the form payload');

foreach ($config as $key => $entry) {
    $t->assertSame(ProviderRegistry::get($key)['fields'], $entry['fields'], $key . ' carries its field list');

    $modelTypes = array_keys($entry['models']);
    sort($modelTypes);
    $t->assertSame($types, $modelTypes, $key . ' carries a model list per type');
}

// A closure sneaking into the payload would end up as an empty object in the page.
$json = json_encode($config, JSON_THROW_ON_ERROR);
$t->assertSame($config, json_decode($json, true), 'the payload survives json encoding unchanged');

// Add a type to Service::getTypes() and forget TYPE_CAPABILITIES and the select stays
// empty for it, with nothing pointing at the cause. Compared as sets: the order in the
// type dropdown is a display decision and may differ.
$offeredTypes = array_keys(Service::getTypes());
$mappedTypes = array_keys($config['openai']['models']);
sort($offeredTypes);
sort($mappedTypes);
$t->assertSame(
    $offeredTypes,
    $mappedTypes,
    'the types the form offers are exactly the types with a capability mapping',
);

// ---------------------------------------------------------------------------
$t->section('Extension point');

rex_extension::register(ProviderRegistry::EXTENSION_POINT, static function (rex_extension_point $ep): array {
    $providers = $ep->getSubject();
    $providers['fromAnotherAddon'] = [
        'label' => 'From another addon',
        'fields' => ['api_key'],
        'defaults' => ['text' => 'some-model'],
        'catalog' => static fn () => new FallbackModelCatalog(),
        'factory' => static fn (array $profile, $httpClient) => GenericFactory::create('https://example.invalid', null, $httpClient),
    ];

    return $providers;
});

$t->assert(isset(ProviderRegistry::all()['fromAnotherAddon']), 'another addon can add a provider');
$t->assertSame('From another addon', ProviderRegistry::labels()['fromAnotherAddon'], 'its label reaches the select');
$t->assert(isset(ProviderRegistry::formConfig()['fromAnotherAddon']), 'its fields reach the form payload');

// ---------------------------------------------------------------------------
$t->section('What goes out on the wire');

// The bridge appends its own versioned path while every provider documents its
// endpoint with the /v1 in it, so both spellings have to reach the same URL.
$baseUrls = [
    'https://ai.example' => 'https://ai.example/v1/chat/completions',
    'https://ai.example/v1' => 'https://ai.example/v1/chat/completions',
    'https://ai.example/v1/' => 'https://ai.example/v1/chat/completions',
    'https://ai.example/openai/v1' => 'https://ai.example/openai/v1/chat/completions',
    '  https://ai.example  ' => 'https://ai.example/v1/chat/completions',
];

foreach ($baseUrls as $baseUrl => $expected) {
    $request = $capture(['provider' => 'generic', 'base_url' => $baseUrl, 'api_key' => 'secret-key'], $completion);
    $t->assertSame($expected, $request['url'], 'generic base url "' . trim($baseUrl) . '" posts to the completions path');
    $t->assertSame('POST', $request['method'], 'generic posts');
}

$withKey = $capture(['provider' => 'generic', 'base_url' => 'https://ai.example', 'api_key' => 'secret-key'], $completion);
$t->assertSame('Bearer secret-key', $authHeader($withKey['headers']), 'the api key goes out as a bearer token');

// An unsecured local endpoint must not receive an empty bearer token.
$withoutKey = $capture(['provider' => 'generic', 'base_url' => 'https://ai.example', 'api_key' => ''], $completion);
$t->assertSame(null, $authHeader($withoutKey['headers']), 'an empty api key sends no authorization header');

$ollama = $capture(
    ['provider' => 'ollama', 'base_url' => 'https://ollama.example', 'api_key' => 'proxy-token', 'model' => 'llama3.2'],
    ['message' => ['role' => 'assistant', 'content' => 'OK'], 'done' => true],
);
$t->assertSame('https://ollama.example/api/chat', $ollama['url'], 'ollama keeps its native endpoint');
$t->assertSame('Bearer proxy-token', $authHeader($ollama['headers']), 'ollama passes the key on as a bearer token');

// A model name outside the provider's catalog is not merely unlisted -- Symfony AI's
// AbstractModelCatalog refuses it, so nothing is sent. This is the difference between the
// two kinds of provider, and the reason the free-text input matters for the generic one:
// its FallbackModelCatalog accepts any name, Ollama's strict catalog does not.
$t->assertThrows(
    static fn () => $capture(
        ['provider' => 'ollama', 'base_url' => 'https://ollama.example', 'model' => 'llama3.2-vision'],
        ['message' => ['content' => 'OK'], 'done' => true],
    ),
    'ollama refuses a model its catalog does not list',
    'not found in',
);

$anyName = $capture(
    ['provider' => 'generic', 'base_url' => 'https://ai.example', 'model' => 'whatever-the-server-calls-it'],
    $completion,
);
$t->assertSame('https://ai.example/v1/chat/completions', $anyName['url'], 'generic accepts any model name');

// OpenRouter is the generic bridge with a fixed base URL, so the same path applies -- and
// the profile needs no base_url field for it.
$openRouter = $capture(
    ['provider' => 'openrouter', 'api_key' => 'or-key', 'model' => 'openai/gpt-4o'],
    $completion,
);
$t->assertSame('https://openrouter.ai/api/v1/chat/completions', $openRouter['url'], 'openrouter posts to its own endpoint');
$t->assertSame('Bearer or-key', $authHeader($openRouter['headers']), 'openrouter passes the key as a bearer token');

// Replicate is not driven end to end here: its client polls a prediction until it is
// finished, so a mock would have to fake that state machine and the test would assert the
// mock rather than the bridge. What matters at this level is that the platform builds and
// takes its api key -- the model call is covered by the connection test in the backend.
$t->assert(
    ProviderRegistry::createPlatform(['provider' => 'replicate', 'api_key' => 'r8-key']) instanceof PlatformInterface,
    'replicate builds a platform from an api key',
);

$t->assertThrows(
    static fn () => ProviderRegistry::createPlatform(['provider' => 'generic', 'api_key' => 'x']),
    'generic without a base url throws',
    'needs a base URL',
);
$t->assertThrows(
    static fn () => ProviderRegistry::createPlatform(['provider' => 'openai', 'api_key' => '']),
    'a provider without its api key throws',
    'needs an API key',
);

exit($t->summary());
