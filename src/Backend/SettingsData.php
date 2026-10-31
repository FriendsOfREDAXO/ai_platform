<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Backend;

use FriendsOfRedaxo\AiPlatform\Service;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Form\Field\Choice;
use Redaxo\Core\Form\Field\Number;
use Redaxo\Core\Translation\Translatable;

/**
 * Formulardaten der Einstellungsseite: Standardprofil je Typ und die Zeitgrenzen für Anfragen an die Anbieter.
 *
 * @internal
 */
final class SettingsData
{
    /** Konfigurationsschlüssel => Eigenschaft. */
    private const array KEYS = [
        'default_text_profile' => 'defaultTextProfile',
        'default_embedding_profile' => 'defaultEmbeddingProfile',
        'default_image_generation_profile' => 'defaultImageGenerationProfile',
        'default_image_understanding_profile' => 'defaultImageUnderstandingProfile',
    ];
    #[Choice(new Translatable('ai_platform_default_text_profile'), choices: self::textProfiles(...), placeholder: new Translatable('ai_platform_no_profile'))]
    public ?int $defaultTextProfile = null;

    #[Choice(new Translatable('ai_platform_default_embedding_profile'), choices: self::embeddingProfiles(...), placeholder: new Translatable('ai_platform_no_profile'))]
    public ?int $defaultEmbeddingProfile = null;

    #[Choice(new Translatable('ai_platform_default_image_generation_profile'), choices: self::imageGenerationProfiles(...), placeholder: new Translatable('ai_platform_no_profile'))]
    public ?int $defaultImageGenerationProfile = null;

    #[Choice(new Translatable('ai_platform_default_image_understanding_profile'), choices: self::imageUnderstandingProfiles(...), placeholder: new Translatable('ai_platform_no_profile'))]
    public ?int $defaultImageUnderstandingProfile = null;

    #[Number(new Translatable('ai_platform_http_timeout'), note: new Translatable('ai_platform_http_timeout_notice'), required: true, min: 1, max: 3600)]
    public int $httpTimeout = 300;

    #[Number(new Translatable('ai_platform_http_max_duration'), note: new Translatable('ai_platform_http_max_duration_notice'), required: true, min: 0, max: 86400)]
    public int $httpMaxDuration = 0;

    public static function fromConfig(Addon $addon): self
    {
        $data = new self();
        foreach (self::KEYS as $key => $property) {
            $id = (int) $addon->getConfig($key, 0);
            $data->$property = $id > 0 ? $id : null;
        }
        $data->httpTimeout = (int) $addon->getConfig('http_timeout', 300);
        $data->httpMaxDuration = (int) $addon->getConfig('http_max_duration', 0);

        return $data;
    }

    public function save(Addon $addon): void
    {
        foreach (self::KEYS as $key => $property) {
            $addon->setConfig($key, $this->$property ?? 0);
        }
        $addon->setConfig('http_timeout', $this->httpTimeout);
        $addon->setConfig('http_max_duration', $this->httpMaxDuration);
    }

    /** @return array<int, string> */
    public static function textProfiles(): array
    {
        return self::profiles('text');
    }

    /** @return array<int, string> */
    public static function embeddingProfiles(): array
    {
        return self::profiles('embedding');
    }

    /** @return array<int, string> */
    public static function imageGenerationProfiles(): array
    {
        return self::profiles('image_generation');
    }

    /** @return array<int, string> */
    public static function imageUnderstandingProfiles(): array
    {
        return self::profiles('image_understanding');
    }

    /** @return array<int, string> */
    private static function profiles(string $type): array
    {
        $choices = [];
        foreach (Service::getInstance()->getProfiles($type) as $profile) {
            $choices[(int) $profile['id']] = $profile['name'] . ' (' . $profile['provider'] . ', ' . $profile['model'] . ')';
        }

        return $choices;
    }
}
