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

        try {
            $platform = $service->getPlatform($profileId);
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
            $options = $service->getProfileOptions($profileId);

            if ('image_generation' === $type) {
                // For image generation, we test with a direct API call
                // to avoid the full Symfony AI result parsing overhead
                $response = 'Bildgenerierung kann nicht automatisch getestet werden. '
                    . 'Bitte die API-Verbindung über ein Text-Profil mit dem gleichen API-Key testen.';

                // If provider is OpenAI, we can at least verify the API key works
                if ('openai' === $profile['provider']) {
                    $httpClient = \Symfony\Component\HttpClient\HttpClient::create();
                    $apiResponse = $httpClient->request('GET', 'https://api.openai.com/v1/models/' . $model, [
                        'auth_bearer' => $profile['api_key'],
                    ]);
                    if (200 === $apiResponse->getStatusCode()) {
                        $response = 'API-Key gueltig, Modell "' . $model . '" verfuegbar.';
                    } else {
                        throw new \RuntimeException('API-Key ungueltig oder Modell nicht verfuegbar (HTTP ' . $apiResponse->getStatusCode() . ')');
                    }
                }
            } elseif ('embedding' === $type) {
                // For embeddings, send a short string and check vectors
                $result = $platform->invoke($model, 'Connection test', $options);
                $vectors = $result->asVectors();
                $dimension = isset($vectors[0]) ? count($vectors[0]->getData()) : 0;
                $response = 'Embedding erfolgreich generiert (Dimensionen: ' . $dimension . ').';
            } else {
                // For text and image_understanding, send a simple text request
                $messages = new \Symfony\AI\Platform\Message\MessageBag(
                    \Symfony\AI\Platform\Message\Message::ofUser('Reply with exactly: OK'),
                );
                $result = $platform->invoke($model, $messages, $options);
                $response = mb_substr($result->asText(), 0, 200);
            }

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
