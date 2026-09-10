<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform;

use rex_exception;
use rex_extension;
use rex_extension_point;
use rex_i18n;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog as AnthropicCatalog;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Cerebras\ModelCatalog as CerebrasCatalog;
use Symfony\AI\Platform\Bridge\Cerebras\Factory as CerebrasFactory;
use Symfony\AI\Platform\Bridge\Gemini\ModelCatalog as GeminiCatalog;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Symfony\AI\Platform\Bridge\Generic\FallbackModelCatalog as GenericCatalog;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog as MistralCatalog;
use Symfony\AI\Platform\Bridge\Mistral\Factory as MistralFactory;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog as OpenAiCatalog;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Bridge\OpenRouter\ModelCatalog as OpenRouterCatalog;
use Symfony\AI\Platform\Bridge\OpenRouter\Factory as OpenRouterFactory;
use Symfony\AI\Platform\Bridge\Replicate\ModelCatalog as ReplicateCatalog;
use Symfony\AI\Platform\Bridge\Replicate\Factory as ReplicateFactory;
use Symfony\AI\Platform\Bridge\Scaleway\ModelCatalog as ScalewayCatalog;
use Symfony\AI\Platform\Bridge\Scaleway\Factory as ScalewayFactory;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The providers a profile can be built on — one entry each, and everything about
 * a provider in that one entry: the label for the select, which credential and
 * option fields the profile form shows for it, the Symfony AI model catalog its
 * model suggestions come from, and the closure that builds the platform.
 *
 * Adding a provider is a `composer require` for its Symfony AI bridge plus one
 * entry here. From outside this addon it is one entry appended in the
 * AI_PLATFORM_PROVIDERS extension point — no fork, no release of this addon:
 *
 *     rex_extension::register(ProviderRegistry::EXTENSION_POINT, function ($ep) {
 *         $providers = $ep->getSubject();
 *         $providers['perplexity'] = [
 *             'label'    => 'Perplexity',
 *             'fields'   => ['api_key'],
 *             'defaults' => ['text' => 'sonar-pro'],
 *             'catalog'  => static fn () => new PerplexityCatalog(),
 *             'factory'  => static fn (array $p, $client) => PerplexityFactory::createPlatform($p['api_key'], $client),
 *         ];
 *         return $providers;
 *     });
 *
 * A key that is already taken *replaces* the built-in provider — that is how one of
 * the shipped providers is given a different catalog or factory, and the reason not
 * to name a new provider after one of them by accident.
 *
 * The definitions are rebuilt on every call on purpose: caching them statically
 * would freeze whichever set existed at the first access, and a provider
 * registered later in the boot order would silently be missing.
 */
final class ProviderRegistry
{
    /** Extension point to add, replace or drop providers. Subject is the definition array. */
    public const EXTENSION_POINT = 'AI_PLATFORM_PROVIDERS';

    /** Credential fields in the profile form. A provider lists the ones it needs in 'fields'. */
    public const CREDENTIAL_FIELDS = ['api_key', 'base_url'];

    /**
     * Provider-specific option fields. Listed in 'fields' like credentials, but shown
     * only when the selected type shows them too — 'image_style' is a DALL-E option and
     * belongs to image generation, not to every OpenAI profile.
     */
    public const OPTION_FIELDS = ['image_quality', 'image_style'];

    /**
     * Profile type => the capabilities a model needs to be offered for that type: all of
     * 'all', and at least one of 'any' when that list is not empty.
     *
     * Both halves earn their keep, gemessen gegen die Kataloge von Symfony AI 0.13.
     * OUTPUT_TEXT on its own also matches speech-to-text: `whisper-1` carries
     * `input-audio|output-text` and would show up under a text profile — hence the
     * demand for a text-ish input, which is exactly the one model it removes from
     * OpenAI's list (56 → 55). But demanding INPUT_MESSAGES specifically is too narrow:
     * the OpenRouter catalog describes its entries with INPUT_TEXT, and that stricter
     * rule leaves **4 of its 538 models** standing. Accepting either keeps whisper out
     * and OpenRouter in, and changes nothing for OpenAI, Anthropic and Gemini
     * (55/27/40 text models under either rule).
     *
     * @var array<string, array{all: list<Capability>, any: list<Capability>}>
     */
    private const TYPE_CAPABILITIES = [
        'text' => [
            'all' => [Capability::OUTPUT_TEXT],
            'any' => [Capability::INPUT_MESSAGES, Capability::INPUT_TEXT],
        ],
        'image_understanding' => [
            'all' => [Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            'any' => [Capability::INPUT_MESSAGES, Capability::INPUT_TEXT],
        ],
        'image_generation' => [
            'all' => [Capability::OUTPUT_IMAGE],
            'any' => [],
        ],
        'embedding' => [
            'all' => [Capability::EMBEDDINGS],
            'any' => [],
        ],
    ];

    /**
     * @return array<string, array{label: string, fields: list<string>, defaults: array<string, string>, catalog: callable(): ModelCatalogInterface, factory: callable(array<string, mixed>, ?HttpClientInterface): PlatformInterface}>
     */
    public static function all(): array
    {
        $providers = [
            'openai' => [
                'label' => 'OpenAI (GPT, gpt-image)',
                // 'image_style' stand hier bis Symfony AI 0.13: es war eine
                // DALL-E-Option, und die dall-e-Eintraege sind aus dem Katalog
                // entfernt ("retired by OpenAI"). Kein Bildmodell dieser Bridge
                // kennt noch ein 'style' -- mitgesendet ergibt es einen 400.
                'fields' => ['api_key', 'image_quality'],
                'defaults' => [
                    'text' => 'gpt-4o',
                    'image_generation' => 'gpt-image-1',
                    'image_understanding' => 'gpt-4o',
                    'embedding' => 'text-embedding-3-small',
                ],
                'catalog' => static fn (): ModelCatalogInterface => new OpenAiCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => OpenAiFactory::createPlatform(
                    self::requireApiKey($profile, 'openai'),
                    $httpClient,
                ),
            ],
            'anthropic' => [
                'label' => 'Anthropic (Claude)',
                'fields' => ['api_key'],
                // Bis Symfony AI 0.12 endete der Katalog bei Sonnet 4.5, weshalb hier
                // ein datierter Name aus 2025 stand. Der nicht datierte Alias zeigt
                // immer auf die aktuelle Fassung des Modells.
                'defaults' => [
                    'text' => 'claude-sonnet-5',
                    'image_understanding' => 'claude-sonnet-5',
                ],
                'catalog' => static fn (): ModelCatalogInterface => new AnthropicCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => AnthropicFactory::createPlatform(
                    self::requireApiKey($profile, 'anthropic'),
                    $httpClient,
                ),
            ],
            'google' => [
                'label' => 'Google (Gemini)',
                'fields' => ['api_key'],
                // gemini-2.0-flash-exp and text-embedding-004 used to stand here; both are
                // gone from the bridge's catalog, so they landed the form on "custom model
                // name" the moment the provider was picked. A default has to be offered by
                // the catalog -- ProviderRegistryTest::testDefaultModelIsOfferedByTheCatalog
                // keeps it that way.
                'defaults' => [
                    'text' => 'gemini-2.5-flash',
                    'image_generation' => 'gemini-2.5-flash-image',
                    'image_understanding' => 'gemini-2.5-flash',
                    'embedding' => 'gemini-embedding-001',
                ],
                'catalog' => static fn (): ModelCatalogInterface => new GeminiCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => GeminiFactory::createPlatform(
                    self::requireApiKey($profile, 'google'),
                    $httpClient,
                ),
            ],
            'ollama' => [
                'label' => 'Ollama (Lokal)',
                'fields' => ['api_key', 'base_url'],
                'defaults' => [
                    'text' => 'llama3.2',
                    'image_understanding' => 'llava',
                    'embedding' => 'nomic-embed-text',
                ],
                // Ollamas Katalog ist seit 0.13 ein Live-Katalog: er fragt den
                // Server (`POST api/show`, `GET api/tags`) und braucht dafuer einen
                // HttpClient. Fuer das Formular ist das nichts -- formConfig() laeuft
                // bei jedem Rendern und darf kein HTTP-Request werden. Hier steht
                // deshalb ein leerer Katalog: die Auswahl entfaellt, es bleibt das
                // Textfeld. Die Platform baut ihren Live-Katalog selbst, weshalb dort
                // jeder Name funktioniert, den der Server geladen hat -- auch
                // llama3.2-vision, das die frueher fest eingebaute Liste nicht kannte.
                'catalog' => static fn (): ModelCatalogInterface => new GenericCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => OllamaFactory::createPlatform(
                    self::baseUrl($profile) ?? 'http://localhost:11434',
                    self::apiKey($profile),
                    $httpClient,
                ),
            ],
            'mistral' => [
                'label' => 'Mistral',
                'fields' => ['api_key'],
                'defaults' => [
                    'text' => 'mistral-medium-latest',
                    'image_understanding' => 'pixtral-large-latest',
                    'embedding' => 'mistral-embed',
                ],
                'catalog' => static fn (): ModelCatalogInterface => new MistralCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => MistralFactory::createPlatform(
                    self::requireApiKey($profile, 'mistral'),
                    $httpClient,
                ),
            ],
            'cerebras' => [
                // Text only, and that is the catalog's own doing: Cerebras hosts open
                // models for fast inference, none of them multimodal.
                'label' => 'Cerebras',
                'fields' => ['api_key'],
                'defaults' => ['text' => 'llama-3.3-70b'],
                'catalog' => static fn (): ModelCatalogInterface => new CerebrasCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => CerebrasFactory::createPlatform(
                    self::requireApiKey($profile, 'cerebras'),
                    $httpClient,
                ),
            ],
            'scaleway' => [
                'label' => 'Scaleway',
                'fields' => ['api_key'],
                'defaults' => [
                    'text' => 'llama-3.3-70b-instruct',
                    'image_understanding' => 'pixtral-12b-2409',
                    'embedding' => 'bge-multilingual-gemma2',
                ],
                'catalog' => static fn (): ModelCatalogInterface => new ScalewayCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => ScalewayFactory::createPlatform(
                    self::requireApiKey($profile, 'scaleway'),
                    $httpClient,
                ),
            ],
            'openrouter' => [
                // Upstream is a thin wrapper around the generic bridge with OpenRouter's
                // base URL, so it speaks the same chat-completions protocol.
                'label' => rex_i18n::msg('ai_platform_provider_openrouter'),
                'fields' => ['api_key'],
                // Defaults matter more here than elsewhere: the catalog's first entry
                // alphabetically is "@preset", OpenRouter's placeholder for a saved
                // preset rather than a model, and it must not be what a new profile lands
                // on.
                'defaults' => [
                    'text' => 'openai/gpt-4o',
                    'image_generation' => 'google/gemini-2.5-flash-image',
                    'image_understanding' => 'openai/gpt-4o',
                    'embedding' => 'google/gemini-embedding-001',
                ],
                // The bridge also ships a ModelApiCatalog that fetches the current list
                // from OpenRouter. Not used here: formConfig() runs on every render of the
                // profile form, and that must not turn into an HTTP request.
                'catalog' => static fn (): ModelCatalogInterface => new OpenRouterCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => OpenRouterFactory::createPlatform(
                    self::requireApiKey($profile, 'openrouter'),
                    $httpClient,
                ),
            ],
            'replicate' => [
                // Symfony AI's Replicate bridge is a Llama client, not general Replicate
                // access: LlamaModelClient, LlamaResultConverter, LlamaMessageBagNormalizer,
                // and a catalog of 15 llama-* entries. Text only — none of the image models
                // Replicate is otherwise known for. The label says so, because the empty
                // model list for the other three types would otherwise look like a bug.
                'label' => rex_i18n::msg('ai_platform_provider_replicate'),
                'fields' => ['api_key'],
                'defaults' => ['text' => 'llama-3.3-70B-Instruct'],
                'catalog' => static fn (): ModelCatalogInterface => new ReplicateCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => ReplicateFactory::createPlatform(
                    self::requireApiKey($profile, 'replicate'),
                    $httpClient,
                ),
            ],
            'generic' => [
                'label' => rex_i18n::msg('ai_platform_provider_generic'),
                'fields' => ['api_key', 'base_url'],
                'defaults' => [],
                'catalog' => static fn (): ModelCatalogInterface => new GenericCatalog(),
                'factory' => static function (array $profile, ?HttpClientInterface $httpClient): PlatformInterface {
                    $baseUrl = self::baseUrl($profile);
                    if (null === $baseUrl) {
                        throw new rex_exception('AI provider "generic" needs a base URL on the profile.');
                    }

                    return GenericFactory::createPlatform($baseUrl, self::apiKey($profile), $httpClient);
                },
            ],
        ];

        /** @var array<string, array{label: string, fields: list<string>, defaults: array<string, string>, catalog: callable(): ModelCatalogInterface, factory: callable(array<string, mixed>, ?HttpClientInterface): PlatformInterface}> */
        return rex_extension::registerPoint(new rex_extension_point(self::EXTENSION_POINT, $providers));
    }

    /**
     * @return array{label: string, fields: list<string>, defaults: array<string, string>, catalog: callable(): ModelCatalogInterface, factory: callable(array<string, mixed>, ?HttpClientInterface): PlatformInterface}
     *
     * @throws rex_exception if no provider is registered under that key
     */
    public static function get(string $provider): array
    {
        $providers = self::all();
        if (!isset($providers[$provider])) {
            throw new rex_exception('Unknown AI provider: ' . $provider);
        }

        return $providers[$provider];
    }

    /**
     * Provider key => label, for the profile form and the list view.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(static fn (array $definition): string => $definition['label'], self::all());
    }

    /**
     * Build the Symfony AI platform for a profile row.
     *
     * Every bridge factory takes an optional HTTP client, and it is passed through here:
     * that is the seam for custom headers, timeouts or a proxy, and it is what lets a test
     * put a MockHttpClient in front of a provider instead of talking to the network.
     *
     * @param array<string, mixed> $profile
     */
    public static function createPlatform(array $profile, ?HttpClientInterface $httpClient = null): PlatformInterface
    {
        $provider = (string) ($profile['provider'] ?? '');

        return (self::get($provider)['factory'])($profile, $httpClient);
    }

    /**
     * Model names the provider's catalog offers for a profile type.
     *
     * These come from the bridge's own catalog, which Symfony AI keeps current — the
     * addon does not maintain model lists. They fill the select on the profile form.
     *
     * The catalog is not exhaustive though: for Ollama `llama3.2-vision` is missing
     * entirely and `llava` carries no INPUT_IMAGE capability, so both drop out of the
     * image-understanding list while working perfectly well. That is why the select
     * always carries a "custom model name" entry that reveals the free-text input, and
     * why an empty list here hides the select rather than replacing the input.
     *
     * @return list<string>
     */
    public static function models(string $provider, string $type): array
    {
        $required = self::TYPE_CAPABILITIES[$type] ?? null;
        if (null === $required) {
            return [];
        }

        try {
            $catalog = (self::get($provider)['catalog'])();
        } catch (rex_exception) {
            return [];
        }

        $models = [];
        foreach ($catalog->getModels() as $name => $definition) {
            $capabilities = $definition['capabilities'];

            foreach ($required['all'] as $capability) {
                if (!in_array($capability, $capabilities, true)) {
                    continue 2;
                }
            }

            if ([] !== $required['any']) {
                $matched = false;
                foreach ($required['any'] as $capability) {
                    if (in_array($capability, $capabilities, true)) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    continue;
                }
            }

            $models[] = $name;
        }

        sort($models);

        return $models;
    }

    /**
     * Everything the profile form's JavaScript needs, in one JSON payload: which fields
     * to show per provider, the default model per provider and type, and the model names
     * that fill the select. Keeping it here means the JS holds no provider knowledge of
     * its own — that duplication is what let the Ollama API-key field stay hidden after
     * the PHP side already supported it.
     *
     * @return array<string, array{fields: list<string>, defaults: array<string, string>, models: array<string, list<string>>}>
     */
    public static function formConfig(): array
    {
        $config = [];
        foreach (self::all() as $key => $definition) {
            $models = [];
            foreach (array_keys(self::TYPE_CAPABILITIES) as $type) {
                $models[$type] = self::models($key, $type);
            }

            $config[$key] = [
                'fields' => $definition['fields'],
                'defaults' => $definition['defaults'],
                'models' => $models,
            ];
        }

        return $config;
    }

    /**
     * Base URL of a profile, or null when the field is empty.
     *
     * A trailing `/v1` is stripped: the bridges append their own versioned path
     * (`/v1/chat/completions` for the generic one), while every provider documents its
     * endpoint *with* the `/v1` — so both spellings have to arrive at the same URL.
     *
     * @param array<string, mixed> $profile
     */
    private static function baseUrl(array $profile): ?string
    {
        $baseUrl = rtrim(trim((string) ($profile['base_url'] ?? '')), '/');
        if ('' === $baseUrl) {
            return null;
        }

        if (str_ends_with($baseUrl, '/v1')) {
            $baseUrl = substr($baseUrl, 0, -3);
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * API key of a profile, or null when the field is empty — the bridges take null to
     * mean "send no Authorization header", which is what a local Ollama expects.
     *
     * @param array<string, mixed> $profile
     */
    private static function apiKey(array $profile): ?string
    {
        $apiKey = trim((string) ($profile['api_key'] ?? ''));

        return '' !== $apiKey ? $apiKey : null;
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @throws rex_exception if the profile has no API key
     */
    private static function requireApiKey(array $profile, string $provider): string
    {
        $apiKey = self::apiKey($profile);
        if (null === $apiKey) {
            throw new rex_exception('AI provider "' . $provider . '" needs an API key on the profile.');
        }

        return $apiKey;
    }
}
