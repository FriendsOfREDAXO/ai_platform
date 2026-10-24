<?php

use FriendsOfRedaxo\AiPlatform\ProviderRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The provider registry, driven through a MockHttpClient: nothing goes over the network.
 *
 * The fuller version of these checks lives in .claude/tests/provider-registry-test.php,
 * which runs against a configured installation.
 *
 * @internal
 */
final class ai_platform_provider_registry_test extends TestCase
{
    public function testShippedProvidersAreRegistered(): void
    {
        self::assertSame(
            ['openai', 'anthropic', 'google', 'ollama', 'mistral', 'cerebras', 'scaleway', 'openrouter', 'replicate', 'generic'],
            array_keys(ProviderRegistry::all()),
        );
    }

    public function testLabelsAreTranslated(): void
    {
        foreach (ProviderRegistry::labels() as $provider => $label) {
            self::assertStringNotContainsString('[translate:', $label, $provider);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function baseUrls(): iterable
    {
        // Providers document their endpoint with the /v1, the bridge appends its own.
        yield 'bare host' => ['https://ai.example', 'https://ai.example/v1/chat/completions'];
        yield 'with /v1' => ['https://ai.example/v1', 'https://ai.example/v1/chat/completions'];
        yield 'with /v1/' => ['https://ai.example/v1/', 'https://ai.example/v1/chat/completions'];
        yield 'with a path' => ['https://ai.example/openai/v1', 'https://ai.example/openai/v1/chat/completions'];
        yield 'with whitespace' => ['  https://ai.example  ', 'https://ai.example/v1/chat/completions'];
    }

    #[DataProvider('baseUrls')]
    public function testGenericBaseUrlIsNormalised(string $baseUrl, string $expected): void
    {
        $url = null;
        $client = new MockHttpClient(static function (string $method, string $requestUrl) use (&$url): MockResponse {
            $url = $requestUrl;

            return new MockResponse(
                json_encode(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $answer = ProviderRegistry::createPlatform(['provider' => 'generic', 'base_url' => $baseUrl, 'api_key' => 'k'], $client)
            ->invoke('any-model', new MessageBag(Message::ofUser('ping')))
            ->asText();

        self::assertSame('OK', $answer);
        self::assertSame($expected, $url);
    }

    public function testGenericWithoutBaseUrlIsRefused(): void
    {
        $this->expectException(Throwable::class);
        ProviderRegistry::createPlatform(['provider' => 'generic', 'api_key' => 'k']);
    }

    public function testSpeechToTextIsNotOfferedAsTextModel(): void
    {
        self::assertNotContains('whisper-1', ProviderRegistry::models('openai', 'text'));
    }

    public function testEveryDefaultModelIsInItsCatalog(): void
    {
        foreach (ProviderRegistry::all() as $provider => $definition) {
            foreach ($definition['defaults'] as $type => $model) {
                $offered = ProviderRegistry::models($provider, $type);
                if ([] === $offered) {
                    continue; // open catalog: any name goes
                }
                self::assertContains($model, $offered, $provider . ' / ' . $type);
            }
        }
    }
}
