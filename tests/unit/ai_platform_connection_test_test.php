<?php

use FriendsOfRedaxo\AiPlatform\Service;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Test\InMemoryPlatform;

/**
 * Service::testConnection(), behind the "Test connection" button (issue #21).
 *
 * A fixture profile is created, and its platform is swapped in the service's
 * per-request cache for an InMemoryPlatform. Nothing goes over the network.
 *
 * @internal
 */
final class ai_platform_connection_test_test extends TestCase
{
    private int $profileId = 0;

    protected function setUp(): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('ai_profile'));
        $sql->setValue('name', 'phpunit-' . uniqid());
        $sql->setValue('type', 'text');
        $sql->setValue('provider', 'generic');
        $sql->setValue('base_url', 'https://ai.invalid');
        $sql->setValue('api_key', 'test-key');
        $sql->setValue('model', 'gpt-5-like');
        $sql->setValue('temperature', '0.7');
        $sql->setValue('status', 1);
        $sql->insert();
        $this->profileId = (int) $sql->getLastId();
    }

    protected function tearDown(): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('ai_profile'));
        $sql->setWhere('id = :id', ['id' => $this->profileId]);
        $sql->delete();
    }

    private function usePlatform(InMemoryPlatform $platform): void
    {
        $cache = new ReflectionProperty(Service::class, 'platformCache');
        $cache->setValue(Service::getInstance(), [$this->profileId => $platform] + $cache->getValue(Service::getInstance()));
    }

    public function testRetriesWithoutTemperatureWhenTheModelRejectsIt(): void
    {
        /** @var list<array<string, mixed>> $calls */
        $calls = [];
        $this->usePlatform(new InMemoryPlatform(static function ($model, $input, array $options) use (&$calls): string {
            $calls[] = $options;
            if (array_key_exists('temperature', $options)) {
                throw new RuntimeException("Unsupported parameter: 'temperature' is not supported with this model.");
            }

            return 'OK';
        }));

        self::assertSame('OK', Service::getInstance()->testConnection($this->profileId));
        self::assertCount(2, $calls);
        self::assertArrayNotHasKey('temperature', $calls[1]);
    }

    public function testSendsTemperatureWhenTheModelAcceptsIt(): void
    {
        /** @var list<array<string, mixed>> $calls */
        $calls = [];
        $this->usePlatform(new InMemoryPlatform(static function ($model, $input, array $options) use (&$calls): string {
            $calls[] = $options;

            return 'OK';
        }));

        self::assertSame('OK', Service::getInstance()->testConnection($this->profileId));
        self::assertCount(1, $calls);
        self::assertSame(0.7, $calls[0]['temperature'] ?? null);
    }

    public function testOtherErrorsStillFail(): void
    {
        $this->usePlatform(new InMemoryPlatform(static function (): string {
            throw new RuntimeException('Incorrect API key provided');
        }));

        $this->expectExceptionMessage('Incorrect API key provided');
        Service::getInstance()->testConnection($this->profileId);
    }
}
