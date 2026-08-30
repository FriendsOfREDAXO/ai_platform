<?php

namespace FriendsOfRedaxo\AiPlatform\Bridge\OpenAiCompatible;

use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Klassisches OpenAI-Chat-Completions-Protokoll (POST {baseUrl}/chat/completions,
 * 'messages'/'model' im Body, Antwort in choices[0].message.content) --
 * das De-facto-Protokoll, das selbstgehostete/kompatible Server (Open WebUI,
 * LiteLLM, vLLM, LM Studio, text-generation-webui, ...) ueblicherweise
 * implementieren. BEWUSST NICHT die neuere OpenAI-eigene "Responses API"
 * (/v1/responses, siehe Symfony AI's eigener OpenAi-Bridge) -- die wird von
 * kompatiblen Drittservern in aller Regel nicht unterstuetzt, waehrend
 * Chat-Completions praktisch universell ist.
 *
 * $payload kommt bereits im richtigen Format von der BASIS-Contract-Klasse
 * (kein bridge-eigener Contract noetig, siehe PlatformFactory): Symfony AI's
 * generische Normalizer (MessageBagNormalizer/UserMessageNormalizer/
 * ImageNormalizer) erzeugen bereits exakt {messages:[...], model:"..."} mit
 * Bild-Content als {type:"image_url", image_url:{url: data-url}} -- das ist
 * bereits das klassische OpenAI-Vision-Format, keine eigene Contract-Klasse
 * noetig.
 */
final class ModelClient implements ModelClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly ?string $apiKey,
        private readonly string $baseUrl,
    ) {}

    public function supports(Model $model): bool
    {
        return true;
    }

    /**
     * @param array<string|int, mixed> $payload
     * @param array<string, mixed>     $options
     */
    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if (is_string($payload)) {
            throw new InvalidArgumentException(sprintf('Payload must be an array, but a string was given to "%s".', self::class));
        }

        $options['stream'] ??= false;

        $headers = ['Content-Type' => 'application/json'];
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        return new RawHttpResult($this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/chat/completions', [
            'headers' => $headers,
            'json' => array_merge($options, $payload),
        ]));
    }
}
