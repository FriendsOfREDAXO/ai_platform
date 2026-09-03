<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform;

use rex_exception;
use rex_extension;
use rex_extension_point;
use rex_i18n;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog as AnthropicCatalog;
use Symfony\AI\Platform\Bridge\Anthropic\PlatformFactory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Gemini\ModelCatalog as GeminiCatalog;
use Symfony\AI\Platform\Bridge\Gemini\PlatformFactory as GeminiFactory;
use Symfony\AI\Platform\Bridge\Generic\FallbackModelCatalog as GenericCatalog;
use Symfony\AI\Platform\Bridge\Generic\PlatformFactory as GenericFactory;
use Symfony\AI\Platform\Bridge\Ollama\ModelCatalog as OllamaCatalog;
use Symfony\AI\Platform\Bridge\Ollama\PlatformFactory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog as OpenAiCatalog;
use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory as OpenAiFactory;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;

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
 *         $providers['mistral'] = [
 *             'label'    => 'Mistral',
 *             'fields'   => ['api_key'],
 *             'defaults' => ['text' => 'mistral-large-latest'],
 *             'catalog'  => static fn () => new MistralCatalog(),
 *             'factory'  => static fn (array $p) => MistralFactory::create($p['api_key']),
 *         ];
 *         return $providers;
 *     });
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
     * Profile type => capabilities a model must have to be suggested for that type.
     *
     * INPUT_MESSAGES is required alongside OUTPUT_TEXT because OUTPUT_TEXT alone also
     * matches speech-to-text models — without it, `whisper-1` shows up as a suggestion
     * for a text profile.
     *
     * @var array<string, list<Capability>>
     */
    private const TYPE_CAPABILITIES = [
        'text' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT],
        'image_understanding' => [Capability::INPUT_MESSAGES, Capability::INPUT_IMAGE, Capability::OUTPUT_TEXT],
        'image_generation' => [Capability::OUTPUT_IMAGE],
        'embedding' => [Capability::EMBEDDINGS],
    ];

    /**
     * @return array<string, array{label: string, fields: list<string>, defaults: array<string, string>, catalog: callable(): ModelCatalogInterface, factory: callable(array<string, mixed>): PlatformInterface}>
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
                'factory' => static fn (array $profile): PlatformInterface => OpenAiFactory::create(
                    self::requireApiKey($profile, 'openai'),
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
                'factory' => static fn (array $profile): PlatformInterface => AnthropicFactory::create(
                    self::requireApiKey($profile, 'anthropic'),
                ),
            ],
            'google' => [
                'label' => 'Google (Gemini)',
                'fields' => ['api_key'],
                'defaults' => [
                    'text' => 'gemini-2.5-flash',
                    'image_generation' => 'gemini-2.0-flash-exp',
                    'image_understanding' => 'gemini-2.5-flash',
                    'embedding' => 'text-embedding-004',
                ],
                'catalog' => static fn (): ModelCatalogInterface => new GeminiCatalog(),
                'factory' => static fn (array $profile): PlatformInterface => GeminiFactory::create(
                    self::requireApiKey($profile, 'google'),
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
                'factory' => static fn (array $profile): PlatformInterface => OllamaFactory::create(
                    self::baseUrl($profile) ?? 'http://localhost:11434',
                    self::apiKey($profile),
                ),
            ],
            'generic' => [
                'label' => rex_i18n::msg('ai_platform_provider_generic'),
                'fields' => ['api_key', 'base_url'],
                'defaults' => [],
                'catalog' => static fn (): ModelCatalogInterface => new GenericCatalog(),
                'factory' => static function (array $profile): PlatformInterface {
                    $baseUrl = self::baseUrl($profile);
                    if (null === $baseUrl) {
                        throw new rex_exception('AI provider "generic" needs a base URL on the profile.');
                    }

                    return GenericFactory::create($baseUrl, self::apiKey($profile));
                },
            ],
        ];

        /** @var array<string, array{label: string, fields: list<string>, defaults: array<string, string>, catalog: callable(): ModelCatalogInterface, factory: callable(array<string, mixed>): PlatformInterface}> */
        return rex_extension::registerPoint(new rex_extension_point(self::EXTENSION_POINT, $providers));
    }

    /**
     * @return array{label: string, fields: list<string>, defaults: array<string, string>, catalog: callable(): ModelCatalogInterface, factory: callable(array<string, mixed>): PlatformInterface}
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
     * @param array<string, mixed> $profile
     */
    public static function createPlatform(array $profile): PlatformInterface
    {
        $provider = (string) ($profile['provider'] ?? '');

        return (self::get($provider)['factory'])($profile);
    }

    /**
     * Model names the provider's catalog offers for a profile type.
     *
     * These come from the bridge's own catalog, which Symfony AI keeps current — the
     * addon does not maintain model lists. The catalog is not exhaustive though: for
     * Ollama `llama3.2-vision` is missing entirely and `llava` carries no INPUT_IMAGE
     * capability, so both drop out of the image-understanding suggestions while working
     * perfectly well. That is why the model field stays free text with these as
     * suggestions, never a select.
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
            foreach ($required as $capability) {
                if (!in_array($capability, $definition['capabilities'], true)) {
                    continue 2;
                }
            }
            $models[] = $name;
        }

        sort($models);

        return $models;
    }

    /**
     * Everything the profile form's JavaScript needs, in one JSON payload: which fields
     * to show per provider, the default model per provider and type, and the suggestions
     * that fill the datalist. Keeping it here means the JS holds no provider knowledge
     * of its own — that duplication is what let the Ollama API-key field stay hidden
     * after the PHP side already supported it.
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
