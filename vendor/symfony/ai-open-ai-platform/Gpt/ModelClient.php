<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\OpenAi\Gpt;

use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Bridge\OpenAi\RegionAwareTrait;
use Symfony\AI\Platform\Bridge\OpenResponses\ModelClient as OpenResponsesModelClient;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\Stream\HttpStreamInterface;
use Symfony\AI\Platform\Result\Stream\SseStream;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ModelClient extends OpenResponsesModelClient
{
    use RegionAwareTrait;

    public function __construct(
        HttpClientInterface $httpClient,
        #[\SensitiveParameter] string $apiKey,
        ?string $region = null,
    ) {
        self::validateApiKey($apiKey);

        $httpClient = $httpClient instanceof EventSourceHttpClient ? $httpClient : new EventSourceHttpClient($httpClient);

        parent::__construct($httpClient, self::getBaseUrl($region), $apiKey, '/v1/responses');
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Gpt;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        // OpenAI performs automatic prompt caching; no explicit cache_control
        // annotation is needed and cacheRetention is not an OpenAI concept.
        // Strip it so it is never forwarded to the Responses API.
        unset($options['cacheRetention']);

        return parent::request($model, $payload, $options);
    }

    protected function createStreamParser(): HttpStreamInterface
    {
        // OpenAI always streams with a proper "text/event-stream" content type.
        return new SseStream();
    }
}
