<?php

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Service;

/**
 * API endpoint to test an AI profile connection.
 *
 * Sends a minimal request to the provider to verify the API key and model work.
 * Called via AJAX from the profile edit page.
 */
class rex_api_ai_test extends rex_api_function
{
    protected $published = false;

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        if (!rex::getUser()?->isAdmin()) {
            rex_response::setStatus('403');
            rex_response::sendJson(['success' => false, 'message' => 'Forbidden']);
            exit;
        }

        $profileId = rex_request('profile_id', 'int', 0);
        if ($profileId <= 0) {
            rex_response::sendJson(['success' => false, 'message' => 'No profile ID given.']);
            exit;
        }

        $service = Service::getInstance();
        $profile = $service->getProfile($profileId);

        if (null === $profile) {
            rex_response::sendJson(['success' => false, 'message' => 'Profile not found or inactive.']);
            exit;
        }

        // Built here first so a broken profile reads "Platform error", not "test failed"
        try {
            $service->getPlatform($profileId);
        } catch (\Throwable $e) {
            rex_response::sendJson(['success' => false, 'message' => 'Platform error: ' . $e->getMessage()]);
            exit;
        }

        $model = $profile['model'] ?? '';
        $type = $profile['type'] ?? 'text';

        if ('' === $model) {
            rex_response::sendJson([
                'success' => false,
                'message' => rex_i18n::msg('ai_platform_test_no_model'),
            ]);
            exit;
        }

        try {
            $response = $service->testConnection($profileId);

            rex_response::sendJson([
                'success' => true,
                'message' => rex_i18n::msg('ai_platform_test_success'),
                'details' => [
                    'provider' => $profile['provider'],
                    'model' => $model,
                    'type' => $type,
                    'response' => $response,
                ],
            ]);
            exit;
        } catch (\Throwable $e) {
            rex_response::sendJson([
                'success' => false,
                'message' => rex_i18n::msg('ai_platform_test_failed'),
                'details' => [
                    'provider' => $profile['provider'],
                    'model' => $model,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ],
            ]);
            exit;
        }
    }
}
