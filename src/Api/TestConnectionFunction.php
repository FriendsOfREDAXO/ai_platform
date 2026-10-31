<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Api;

use FriendsOfRedaxo\AiPlatform\ProviderRegistry;
use FriendsOfRedaxo\AiPlatform\Service;
use Redaxo\Core\ApiFunction\ApiFunction;
use Redaxo\Core\ApiFunction\AsApiFunction;
use Redaxo\Core\Core;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Http\Response;
use Redaxo\Core\Translation\I18n;
use Throwable;

/**
 * Verbindungstest eines Profils für den Knopf auf der Profilseite: schickt die kleinste Anfrage, die zeigt, dass
 * Schlüssel und Modell funktionieren, und antwortet mit JSON.
 *
 * Nur im Backend, nur für Admins, mit CSRF-Token in der URL ({@see self::getTestUrl()}): ein Aufruf kostet beim
 * Anbieter Geld, eine fremde Seite soll ihn nicht auslösen können.
 */
#[AsApiFunction('ai_platform_test')]
final class TestConnectionFunction extends ApiFunction
{
    public static function getTestUrl(int $profileId): string
    {
        return Url::backendController(['profile_id' => $profileId] + self::getUrlParams());
    }

    public function execute(): never
    {
        Response::cleanOutputBuffers();

        if (!Core::getUser()?->admin) {
            Response::setStatus(Response::HTTP_FORBIDDEN);
            self::send(false, 'Forbidden');
        }

        $profileId = Request::get('profile_id', 'int', 0);
        $service = Service::getInstance();
        $profile = $profileId > 0 ? $service->getProfile($profileId) : null;

        if (null === $profile) {
            self::send(false, I18n::msg('ai_platform_test_profile_missing'));
        }

        $details = [
            'provider' => (string) $profile['provider'],
            'model' => (string) $profile['model'],
            'type' => (string) $profile['type'],
            'env' => ProviderRegistry::apiKeyEnv($profile),
        ];

        if ('' === $details['model']) {
            self::send(false, I18n::msg('ai_platform_test_no_model'), $details);
        }

        try {
            $details['response'] = $service->testConnection($profileId);
        } catch (Throwable $e) {
            self::send(false, I18n::msg('ai_platform_test_failed'), $details + ['error' => $e->getMessage()]);
        }

        self::send(true, I18n::msg('ai_platform_test_success'), $details);
    }

    /** @param array<string, string> $details */
    private static function send(bool $success, string $message, array $details = []): never
    {
        Response::sendJson(['success' => $success, 'message' => $message, 'details' => $details]);
        exit;
    }
}
