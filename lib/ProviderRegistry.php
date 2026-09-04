<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform;

use rex_exception;
use rex_extension;
use rex_extension_point;
use rex_i18n;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog as AnthropicCatalog;
use Symfony\AI\Platform\Bridge\Anthropic\PlatformFactory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Cerebras\ModelCatalog as CerebrasCatalog;
use Symfony\AI\Platform\Bridge\Cerebras\PlatformFactory as CerebrasFactory;
use Symfony\AI\Platform\Bridge\Gemini\ModelCatalog as GeminiCatalog;
use Symfony\AI\Platform\Bridge\Gemini\PlatformFactory as GeminiFactory;
use Symfony\AI\Platform\Bridge\Generic\FallbackModelCatalog as GenericCatalog;
use Symfony\AI\Platform\Bridge\Generic\PlatformFactory as GenericFactory;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog as MistralCatalog;
use Symfony\AI\Platform\Bridge\Mistral\PlatformFactory as MistralFactory;
use Symfony\AI\Platform\Bridge\Ollama\ModelCatalog as OllamaCatalog;
use Symfony\AI\Platform\Bridge\Ollama\PlatformFactory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog as OpenAiCatalog;
use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory as OpenAiFactory;
use Symfony\AI\Platform\Bridge\OpenRouter\ModelCatalog as OpenRouterCatalog;
use Symfony\AI\Platform\Bridge\OpenRouter\PlatformFactory as OpenRouterFactory;
use Symfony\AI\Platform\Bridge\Replicate\ModelCatalog as ReplicateCatalog;
use Symfony\AI\Platform\Bridge\Replicate\PlatformFactory as ReplicateFactory;
use Symfony\AI\Platform\Bridge\Scaleway\ModelCatalog as ScalewayCatalog;
use Symfony\AI\Platform\Bridge\Scaleway\PlatformFactory as ScalewayFactory;
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
 *             'factory'  => static fn (array $p, $client) => PerplexityFactory::create($p['api_key'], $client),
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
     * Both halves earn their keep. OUTPUT_TEXT on its own also matches speech-to-text, so
     * `whisper-1` would show up under a text profile — hence the demand for a text-ish
     * input. But demanding INPUT_MESSAGES specifically is too narrow: the OpenRouter
     * catalog describes its entries with INPUT_TEXT, and requiring INPUT_MESSAGES left 2
     * of its 362 models standing. Accepting either keeps whisper out and OpenRouter in,
     * and changes nothing for OpenAI, Anthropic, Gemini and Ollama (19/14/9/19 models
     * before and after).
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
                'label' => 'OpenAI (GPT, DALL-E)',
                'fields' => ['api_key', 'image_quality', 'image_style'],
                'defaults' => [
                    'text' => 'gpt-4o',
                    'image_generation' => 'dall-e-3',
                    'image_understanding' => 'gpt-4o',
                    'embedding' => 'text-embedding-3-small',
                ],
                'catalog' => static fn (): ModelCatalogInterface => new OpenAiCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => OpenAiFactory::create(
                    self::requireApiKey($profile, 'openai'),
                    $httpClient,
                ),
            ],
            'anthropic' => [
                'label' => 'Anthropic (Claude)',
                'fields' => ['api_key'],
                'defaults' => [
                    'text' => 'claude-sonnet-4-20250514',
                    'image_understanding' => 'claude-sonnet-4-20250514',
                ],
                'catalog' => static fn (): ModelCatalogInterface => new AnthropicCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => AnthropicFactory::create(
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
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => GeminiFactory::create(
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
                'catalog' => static fn (): ModelCatalogInterface => new OllamaCatalog(),
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => OllamaFactory::create(
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
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => MistralFactory::create(
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
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => CerebrasFactory::create(
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
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => ScalewayFactory::create(
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
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => OpenRouterFactory::create(
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
                'factory' => static fn (array $profile, ?HttpClientInterface $httpClient): PlatformInterface => ReplicateFactory::create(
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

                    return GenericFactory::create($baseUrl, self::apiKey($profile), $httpClient);
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
