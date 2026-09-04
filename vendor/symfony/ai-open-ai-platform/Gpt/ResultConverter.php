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
use Symfony\AI\Platform\Bridge\OpenResponses\ResultConverter as OpenResponsesResultConverter;
use Symfony\AI\Platform\Model;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Denis Zunke <denis.zunke@gmail.com>
 */
final class ResultConverter extends OpenResponsesResultConverter
{
    public function supports(Model $model): bool
    {
        return $model instanceof Gpt;
    }

    public function getTokenUsageExtractor(): TokenUsageExtractor
    {
        return new TokenUsageExtractor();
    }

    protected function extractRateLimitReset(ResponseInterface $response): ?int
    {
        $headers = $response->getHeaders(false);
        $resetTime = $headers['x-ratelimit-reset-requests'][0]
            ?? $headers['x-ratelimit-reset-tokens'][0]
            ?? null;

        return null !== $resetTime ? self::parseResetTime($resetTime) : null;
    }

    /**
     * Converts OpenAI's reset time format (e.g. "1s", "6m0s", "2m30s") into seconds.
     *
     * Supported formats:
     * - "1s"
     * - "6m0s"
     * - "2m30s"
     */
    private static function parseResetTime(string $resetTime): ?int
    {
        if (preg_match('/^(?:(\d+)m)?(?:(\d+)s)?$/', $resetTime, $matches)) {
            $minutes = isset($matches[1]) ? (int) $matches[1] : 0;
            $secs = isset($matches[2]) ? (int) $matches[2] : 0;

            return ($minutes * 60) + $secs;
        }

        return null;
    }
}
