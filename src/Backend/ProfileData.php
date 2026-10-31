<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Backend;

use FriendsOfRedaxo\AiPlatform\ProviderRegistry;
use FriendsOfRedaxo\AiPlatform\Service;
use Redaxo\Core\Form\Field\Checkbox;
use Redaxo\Core\Form\Field\Choice;
use Redaxo\Core\Form\Field\Number;
use Redaxo\Core\Form\Field\Text;
use Redaxo\Core\Form\Field\Textarea;
use Redaxo\Core\Translation\Translatable;

use function is_string;

/**
 * Formulardaten einer Zeile von `rex_ai_profile` (Backend-Seite „Profile").
 *
 * Welche Felder zu einem Typ und einem Anbieter gehören, entscheidet `assets/profiles.js` anhand von
 * {@see ProviderRegistry::formConfig()}; das Formular enthält immer alle, ausgeblendete
 * Felder behalten ihren Wert.
 *
 * @internal
 */
final class ProfileData
{
    /** Spalte => Eigenschaft. */
    private const array COLUMNS = [
        'name' => 'name',
        'type' => 'type',
        'provider' => 'provider',
        'api_key_env' => 'apiKeyEnv',
        'base_url' => 'baseUrl',
        'model' => 'model',
        'temperature' => 'temperature',
        'max_tokens' => 'maxTokens',
        'system_prompt' => 'systemPrompt',
        'image_size' => 'imageSize',
        'image_quality' => 'imageQuality',
        'image_style' => 'imageStyle',
        'detail_level' => 'detailLevel',
    ];
    #[Text(new Translatable('ai_platform_profile_name'), note: new Translatable('ai_platform_profile_name_notice'), required: true, maxLength: 191)]
    public string $name = '';

    #[Choice(new Translatable('ai_platform_type'), choices: Service::getTypes(...), required: true)]
    public string $type = 'text';

    #[Choice(new Translatable('ai_platform_provider'), choices: self::providerChoices(...), required: true)]
    public string $provider = '';

    #[Text(new Translatable('ai_platform_api_key_env'), note: new Translatable('ai_platform_api_key_env_notice'), maxLength: 100)]
    public ?string $apiKeyEnv = null;

    #[Text(new Translatable('ai_platform_base_url'), note: new Translatable('ai_platform_base_url_notice'), maxLength: 500)]
    public ?string $baseUrl = null;

    #[Text(new Translatable('ai_platform_model'), note: new Translatable('ai_platform_model_notice'), required: true, maxLength: 255)]
    public string $model = '';

    #[Number(new Translatable('ai_platform_temperature'), note: new Translatable('ai_platform_temperature_notice'), min: 0, max: 2, step: '0.1')]
    public ?float $temperature = 1.0;

    #[Number(new Translatable('ai_platform_max_tokens'), note: new Translatable('ai_platform_max_tokens_notice'), min: 1, max: 1000000)]
    public ?int $maxTokens = 4096;

    #[Textarea(new Translatable('ai_platform_system_prompt'), note: new Translatable('ai_platform_system_prompt_notice'), rows: 4)]
    public ?string $systemPrompt = null;

    // Die Größen der gpt-image-Modelle; die DALL-E-Größen sind mit Symfony AI 0.13 aus dem Katalog verschwunden.
    #[Choice(new Translatable('ai_platform_image_size'), choices: ['1024x1024' => '1024x1024', '1536x1024' => '1536x1024 (Querformat)', '1024x1536' => '1024x1536 (Hochformat)'], placeholder: new Translatable('ai_platform_default'))]
    public ?string $imageSize = '1024x1024';

    #[Choice(new Translatable('ai_platform_image_quality'), choices: ['auto' => 'Auto', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'], placeholder: new Translatable('ai_platform_default'))]
    public ?string $imageQuality = null;

    // Kein mitgelieferter Anbieter zeigt das Feld noch (DALL-E-Option); ein per Extension Point registrierter kann es.
    #[Choice(new Translatable('ai_platform_image_style'), choices: ['vivid' => 'Vivid', 'natural' => 'Natural'], placeholder: new Translatable('ai_platform_default'))]
    public ?string $imageStyle = null;

    #[Choice(new Translatable('ai_platform_detail_level'), choices: ['auto' => 'Auto', 'low' => 'Low', 'high' => 'High'], placeholder: new Translatable('ai_platform_default'))]
    public ?string $detailLevel = 'auto';

    #[Checkbox(new Translatable('ai_platform_status_active'))]
    public bool $status = true;

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $data = new self();
        foreach (self::COLUMNS as $column => $property) {
            $value = $row[$column] ?? null;
            $data->$property = match ($property) {
                'temperature' => null === $value || '' === $value ? null : (float) $value,
                'maxTokens' => null === $value || '' === $value ? null : (int) $value,
                'name', 'type', 'provider', 'model' => (string) $value,
                default => null === $value || '' === $value ? null : (string) $value,
            };
        }
        $data->status = 1 === (int) ($row['status'] ?? 1);

        return $data;
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        $row = [];
        foreach (self::COLUMNS as $column => $property) {
            $value = $this->$property;
            $row[$column] = is_string($value) && '' === trim($value) ? null : (is_string($value) ? trim($value) : $value);
        }
        $row['status'] = $this->status ? 1 : 0;

        return $row;
    }

    /** @return array<string, string> */
    public static function providerChoices(): array
    {
        return Service::getProviders();
    }
}
