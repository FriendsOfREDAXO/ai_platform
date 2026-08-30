<?php

namespace FriendsOfRedaxo\AiPlatform\Bridge\OpenAiCompatible;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * Klassisches Chat-Completions-Antwortformat: choices[0].message.content.
 * Kein eigener TokenUsageExtractor (null erlaubt laut Interface) --
 * Nutzungsstatistiken sind fuer diesen generischen Provider nicht
 * standardisiert genug, um sie zuverlaessig auszuwerten.
 */
final class ResultConverter implements ResultConverterInterface
{
    public function supports(Model $model): bool
    {
        return true;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $data = $result->getData();

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException('Response does not contain choices[0].message.content.');
        }

        return new TextResult($content);
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}
