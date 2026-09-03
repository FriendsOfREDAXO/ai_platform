<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform;

use rex;
use rex_config;
use rex_exception;
use rex_extension;
use rex_extension_point;
use rex_i18n;
use rex_sql;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;

class Service
{
    private static ?self $instance = null;

    /** @var array<int, array<string, mixed>> */
    private array $profileCache = [];

    /** @var array<int, PlatformInterface> */
    private array $platformCache = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return array<string, string>
     */
    public static function getTypes(): array
    {
        return [
            'text' => rex_i18n::msg('ai_platform_type_text'),
            'embedding' => rex_i18n::msg('ai_platform_type_embedding'),
            'image_generation' => rex_i18n::msg('ai_platform_type_image_generation'),
            'image_understanding' => rex_i18n::msg('ai_platform_type_image_understanding'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getProviders(): array
    {
        return ProviderRegistry::labels();
    }

    /**
     * Get suggested models for a given provider and type.
     *
     * Comes from the provider's Symfony AI model catalog, filtered by the capabilities
     * the type needs — see ProviderRegistry::models() for why these stay suggestions
     * rather than becoming a closed list.
     *
     * @return list<string>
     */
    public static function getModelSuggestions(string $provider, string $type): array
    {
        return ProviderRegistry::models($provider, $type);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProfile(int $id): ?array
    {
        if (isset($this->profileCache[$id])) {
            return $this->profileCache[$id];
        }

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('ai_profile') . ' WHERE id = ? AND status = 1', [$id]);

        if (0 === $sql->getRows()) {
            return null;
        }

        $profile = [];
        foreach ($sql->getFieldnames() as $field) {
            $profile[$field] = $sql->getValue($field);
        }
        $profile['id'] = (int) $profile['id'];

        $this->profileCache[$id] = $profile;
        return $profile;
    }

    /**
     * Get all active profiles, optionally filtered by type.
     *
     * @return list<array<string, mixed>>
     */
    public function getProfiles(?string $type = null): array
    {
        $query = 'SELECT * FROM ' . rex::getTable('ai_profile') . ' WHERE status = 1';
        $params = [];
        if (null !== $type) {
            $query .= ' AND type = ?';
            $params[] = $type;
        }
        $query .= ' ORDER BY type, name';

        $sql = rex_sql::factory();
        $sql->setQuery($query, $params);

        $profiles = [];
        foreach ($sql as $row) {
            $profile = [];
            foreach ($row->getFieldnames() as $field) {
                $profile[$field] = $row->getValue($field);
            }
            $profile['id'] = (int) $profile['id'];
            $profiles[] = $profile;
        }

        return $profiles;
    }

    /**
     * Create a Symfony AI Platform instance for a given profile.
     */
    public function getPlatform(int $profileId): PlatformInterface
    {
        if (isset($this->platformCache[$profileId])) {
            return $this->platformCache[$profileId];
        }

        $profile = $this->getProfile($profileId);
        if (null === $profile) {
            throw new rex_exception('AI profile not found: ' . $profileId);
        }

        $platform = ProviderRegistry::createPlatform($profile);

        $this->platformCache[$profileId] = $platform;
        return $platform;
    }

    /**
     * Get the default platform for a specific use case.
     */
    public function getDefaultPlatform(string $type): PlatformInterface
    {
        $profileId = (int) rex_config::get('ai_platform', 'default_' . $type . '_profile', 0);
        if (0 === $profileId) {
            throw new rex_exception('No default AI profile configured for: ' . $type);
        }
        return $this->getPlatform($profileId);
    }

    /**
     * Get the default model name for a specific use case.
     */
    public function getDefaultModel(string $type): string
    {
        $profileId = (int) rex_config::get('ai_platform', 'default_' . $type . '_profile', 0);
        $profile = $this->getProfile($profileId);
        if (null === $profile) {
            throw new rex_exception('No default AI profile configured for: ' . $type);
        }
        return $profile['model'] ?? '';
    }

    /**
     * Get the default profile for a specific use case.
     *
     * @return array<string, mixed>
     */
    public function getDefaultProfile(string $type): array
    {
        $profileId = (int) rex_config::get('ai_platform', 'default_' . $type . '_profile', 0);
        $profile = $this->getProfile($profileId);
        if (null === $profile) {
            throw new rex_exception('No default AI profile configured for: ' . $type);
        }
        return $profile;
    }

    /**
     * Build options array from a profile for platform invoke.
     *
     * @return array<string, mixed>
     */
    public function getProfileOptions(int $profileId): array
    {
        $profile = $this->getProfile($profileId);
        if (null === $profile) {
            return [];
        }

        $options = [];
        $provider = $profile['provider'] ?? '';
        $type = $profile['type'] ?? 'text';

        // Text + Image Understanding options
        if ('text' === $type || 'image_understanding' === $type) {
            if ('' !== ($profile['temperature'] ?? '')) {
                $options['temperature'] = (float) $profile['temperature'];
            }
            if ('' !== ($profile['max_tokens'] ?? '') && (int) $profile['max_tokens'] > 0) {
                // OpenAI Responses API uses 'max_output_tokens', others use 'max_tokens'
                $key = 'openai' === $provider ? 'max_output_tokens' : 'max_tokens';
                $options[$key] = (int) $profile['max_tokens'];
            }
        }

        // Image Generation options
        if ('image_generation' === $type) {
            if ('' !== ($profile['image_size'] ?? '')) {
                $options['size'] = $profile['image_size'];
            }
            if ('' !== ($profile['image_quality'] ?? '')) {
                $options['quality'] = $profile['image_quality'];
            }
            if ('' !== ($profile['image_style'] ?? '')) {
                $options['style'] = $profile['image_style'];
            }
        }

        return $options;
    }

    /**
     * Simple text completion helper.
     */
    public function generateText(string $prompt, ?string $systemPrompt = null, ?int $profileId = null): string
    {
        if (null === $profileId) {
            $profile = $this->getDefaultProfile('text');
            $profileId = $profile['id'];
        } else {
            $profile = $this->getProfile($profileId);
        }

        $platform = $this->getPlatform($profileId);
        $model = $profile['model'];
        $options = $this->getProfileOptions($profileId);

        $messages = [];
        // Use profile system prompt if no explicit one given
        $sysPrompt = $systemPrompt ?? ($profile['system_prompt'] ?? '');
        if ('' !== $sysPrompt) {
            $messages[] = Message::forSystem($sysPrompt);
        }
        $messages[] = Message::ofUser($prompt);

        $result = $platform->invoke($model, new MessageBag(...$messages), $options);
        return $result->asText();
    }

    /**
     * Image understanding helper.
     */
    public function understandImage(string $prompt, string $imagePath, ?int $profileId = null): string
    {
        if (null === $profileId) {
            $profile = $this->getDefaultProfile('image_understanding');
            $profileId = $profile['id'];
        } else {
            $profile = $this->getProfile($profileId);
        }

        $platform = $this->getPlatform($profileId);
        $model = $profile['model'];
        $options = $this->getProfileOptions($profileId);

        $messages = new MessageBag(
            Message::ofUser($prompt, Image::fromFile($imagePath)),
        );

        $result = $platform->invoke($model, $messages, $options);
        return $result->asText();
    }

    /**
     * Image generation helper.
     */
    public function generateImage(string $prompt, ?int $profileId = null): string
    {
        if (null === $profileId) {
            $profile = $this->getDefaultProfile('image_generation');
            $profileId = $profile['id'];
        } else {
            $profile = $this->getProfile($profileId);
        }

        $platform = $this->getPlatform($profileId);
        $model = $profile['model'];
        $options = $this->getProfileOptions($profileId);
        $options['response_format'] = 'url';

        $result = $platform->invoke($model, $prompt, $options);
        return $result->asText();
    }


    /**
     * Generate an embedding vector for a given text.
     * Takes a single string and returns a single array of floats,
     * or takes an array of strings and returns an array of float arrays.
     *
     * @param string|array<string> $prompt
     * @return array<float>|array<int, array<float>>
     */
    public function generateEmbedding(string|array $prompt, ?int $profileId = null): array
    {
        if (null === $profileId) {
            $profile = $this->getDefaultProfile('embedding');
            $profileId = $profile['id'];
        } else {
            $profile = $this->getProfile($profileId);
        }

        $platform = $this->getPlatform($profileId);
        $model = (string) ($profile['model'] ?? '');
        if ('' === $model) {
            throw new rex_exception('No model configured for embedding profile id: ' . $profileId);
        }
        $options = $this->getProfileOptions($profileId);

        $result = $platform->invoke($model, $prompt, $options);

        $vectors = $result->asVectors();

        $embeddings = [];
        foreach ($vectors as $vector) {
            $embeddings[] = $vector->getData();
        }

        if (is_string($prompt)) {
            return $embeddings[0] ?? [];
        }

        return $embeddings;
    }

    /**
     * Create an Agent with tool support.
     *
     * @param array<object> $additionalTools
     */
    public function createAgent(string $type = 'text', array $additionalTools = [], ?int $profileId = null): Agent
    {
        if (null === $profileId) {
            $profile = $this->getDefaultProfile($type);
            $profileId = $profile['id'];
        } else {
            $profile = $this->getProfile($profileId);
        }

        $platform = $this->getPlatform($profileId);
        $model = $profile['model'];

        $tools = rex_extension::registerPoint(new rex_extension_point(
            'AI_PLATFORM_AGENT_TOOLS',
            $additionalTools,
            ['type' => $type],
        ));

        $toolbox = new Toolbox($tools);
        $processor = new AgentProcessor($toolbox);

        return new Agent($platform, $model, [$processor], [$processor]);
    }
}
