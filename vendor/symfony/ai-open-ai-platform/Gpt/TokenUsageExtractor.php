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

use Symfony\AI\Platform\Bridge\OpenResponses\TokenUsageExtractor as OpenResponsesTokenUsageExtractor;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @author Denis Zunke <denis.zunke@gmail.com>
 */
final class TokenUsageExtractor extends OpenResponsesTokenUsageExtractor
{
    protected function extractRemainingTokens(RawResultInterface $rawResult): ?int
    {
        $rawResponse = $rawResult->getObject();
        if (!$rawResponse instanceof ResponseInterface) {
            return null;
        }

        $remainingTokens = $rawResponse->getHeaders(false)['x-ratelimit-remaining-tokens'][0] ?? null;

        return null !== $remainingTokens ? (int) $remainingTokens : null;
    }
}
