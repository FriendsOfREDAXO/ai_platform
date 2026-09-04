<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Mistral\Llm;

use Symfony\AI\Platform\Bridge\Generic\Completions\CompletionsConversionTrait;
use Symfony\AI\Platform\Bridge\Generic\Completions\FinishReasonMapper;
use Symfony\AI\Platform\Bridge\Mistral\Mistral;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ChoiceResult;
use Symfony\AI\Platform\Result\HttpStatusErrorHandlingTrait;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\ResultConverterInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ResultConverter implements ResultConverterInterface
{
    use CompletionsConversionTrait {
        yieldContentDeltas as private yieldOpenAiContentDeltas;
        convertChoice as private convertOpenAiChoice;
    }
    use HttpStatusErrorHandlingTrait;

    public function supports(Model $model): bool
    {
        return $model instanceof Mistral;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): ResultInterface
    {
        $httpResponse = $result->getObject();

        if (400 === $httpResponse->getStatusCode()) {
            $body = json_decode($httpResponse->getContent(false), true) ?? [];
            $code = $body['error']['code'] ?? $body['code'] ?? null;
            $message = $body['error']['message'] ?? $body['message'] ?? '';

            if ('context_length_exceeded' === $code || str_contains($message, 'maximum context length')) {
                throw new ExceedContextSizeException('' !== $message ? $message : 'Context size exceeded');
            }
        }

        $this->throwOnHttpError($httpResponse);

        if (($code = $httpResponse->getStatusCode()) >= 400) {
            throw new RuntimeException(\sprintf('Unexpected response code %d: "%s"', $code, $httpResponse->getContent(false)));
        }

        if ($options['stream'] ?? false) {
            return new StreamResult($this->convertStream($result));
        }

        $data = $result->getData();

        if (!isset($data['choices'])) {
            throw new RuntimeException('Response does not contain choices.');
        }

        $choices = array_map($this->convertChoice(...), $data['choices']);

        return 1 === \count($choices) ? $choices[0] : new ChoiceResult($choices);
    }

    public function getTokenUsageExtractor(): TokenUsageExtractor
    {
        return new TokenUsageExtractor();
    }

    /**
     * @param array<string, mixed> $delta
     *
     * @return \Generator<int, ThinkingDelta|ThinkingComplete|TextDelta, mixed, string>
     */
    protected function yieldContentDeltas(array $delta, string $reasoning): \Generator
    {
        $content = $delta['content'] ?? null;

        if (!\is_array($content)) {
            return yield from $this->yieldOpenAiContentDeltas($delta, $reasoning);
        }

        foreach ($content as $chunk) {
            $type = \is_array($chunk) ? ($chunk['type'] ?? null) : null;

            if ('thinking' === $type) {
                $thinking = $this->flattenThinking($chunk['thinking'] ?? []);
                if ('' !== $thinking) {
                    $reasoning .= $thinking;
                    yield new ThinkingDelta($thinking);
                }

                continue;
            }

            if ('text' === $type) {
                if ('' !== $reasoning) {
                    yield new ThinkingComplete($reasoning);
                    $reasoning = '';
                }

                $text = \is_string($chunk['text'] ?? null) ? $chunk['text'] : '';
                if ('' !== $text) {
                    yield new TextDelta($text);
                }
            }
        }

        return $reasoning;
    }

    /**
     * @param array<string, mixed> $choice
     */
    protected function convertChoice(array $choice): ResultInterface
    {
        $content = $choice['message']['content'] ?? null;

        if (!\is_array($content) || 'tool_calls' === ($choice['finish_reason'] ?? null)) {
            return $this->convertOpenAiChoice($choice);
        }

        $results = [];
        foreach ($content as $chunk) {
            $type = \is_array($chunk) ? ($chunk['type'] ?? null) : null;

            if ('thinking' === $type) {
                $results[] = new ThinkingResult($this->flattenThinking($chunk['thinking'] ?? []));
            } elseif ('text' === $type && \is_string($chunk['text'] ?? null)) {
                $results[] = new TextResult($chunk['text']);
            }
        }

        if ([] === $results) {
            $results[] = new TextResult('');
        }

        return $this->withFinishReason(
            1 === \count($results) ? $results[0] : new MultiPartResult($results),
            FinishReasonMapper::map($choice['finish_reason'] ?? null),
        );
    }

    /**
     * @param list<array<string, mixed>> $chunks
     */
    private function flattenThinking(array $chunks): string
    {
        $thinking = '';
        foreach ($chunks as $chunk) {
            if ('text' === ($chunk['type'] ?? null) && \is_string($chunk['text'] ?? null)) {
                $thinking .= $chunk['text'];
            }
        }

        return $thinking;
    }
}
