<?php

/**
 * Service::testConnection() -- the "Test connection" button on the profile page.
 *
 * Issue #21: a model that rejects `temperature` (OpenAI's GPT-5 line) failed the
 * connection test with "Unsupported parameter: 'temperature' is not supported with
 * this model", while generateText() with the same profile worked. The button called
 * invoke() directly and missed the fallback generateText() has. The test now runs
 * through that fallback, and this file pins it.
 *
 * Nothing goes over the network: a fixture profile is created and its platform is
 * replaced in the service's per-request cache by an InMemoryPlatform that behaves
 * like such a model. The profile is removed at the end.
 */

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Service;
use Symfony\AI\Platform\Test\InMemoryPlatform;

require __DIR__ . '/bootstrap.php';

$t = new AiTestRunner('Connection check');

$sql = rex_sql::factory();
$sql->setTable(rex::getTable('ai_profile'));
$sql->setValue('name', 'connection-check-test-' . uniqid());
$sql->setValue('type', 'text');
$sql->setValue('provider', 'generic');
$sql->setValue('base_url', 'https://ai.invalid');
$sql->setValue('api_key', 'test-key');
$sql->setValue('model', 'gpt-5-like');
$sql->setValue('temperature', '0.7');
$sql->setValue('status', 1);
$sql->insert();
$profileId = (int) $sql->getLastId();

$removeProfile = static function () use ($profileId): void {
    $delete = rex_sql::factory();
    $delete->setTable(rex::getTable('ai_profile'));
    $delete->setWhere('id = :id', ['id' => $profileId]);
    $delete->delete();
};
register_shutdown_function($removeProfile);

$service = Service::getInstance();

/** Put a platform in place of the one the profile would build. */
$usePlatform = static function (InMemoryPlatform $platform) use ($service, $profileId): void {
    $cache = new ReflectionProperty(Service::class, 'platformCache');
    $cache->setValue($service, [$profileId => $platform] + $cache->getValue($service));
};

try {
    // -----------------------------------------------------------------------
    $t->section('The fixture');

    $t->assert($profileId > 0, 'the fixture profile was created');
    $t->assertSame(0.7, $service->getProfileOptions($profileId)['temperature'] ?? null, 'and its options carry a temperature');

    // -----------------------------------------------------------------------
    $t->section('A model that rejects temperature');

    $calls = [];
    $usePlatform(new InMemoryPlatform(static function ($model, $input, array $options) use (&$calls): string {
        $calls[] = $options;
        if (array_key_exists('temperature', $options)) {
            // The message from the issue, verbatim.
            throw new RuntimeException("Unsupported parameter: 'temperature' is not supported with this model.");
        }
        return 'OK';
    }));

    $error = null;
    try {
        $answer = $service->testConnection($profileId);
    } catch (Throwable $e) {
        $answer = null;
        $error = $e->getMessage();
    }
    $t->assertSame(null, $error, 'the connection test does not fail on it');
    $t->assertSame('OK', $answer, 'and returns the model answer');
    $t->assertSame(2, count($calls), 'after one retry');
    $t->assert(!array_key_exists('temperature', $calls[1] ?? ['temperature' => 1]), 'the retry is sent without temperature');

    // -----------------------------------------------------------------------
    $t->section('A model that accepts it');

    $calls = [];
    $usePlatform(new InMemoryPlatform(static function ($model, $input, array $options) use (&$calls): string {
        $calls[] = $options;
        return 'OK';
    }));

    $t->assertSame('OK', $service->testConnection($profileId), 'answers');
    $t->assertSame(1, count($calls), 'in one call');
    $t->assertSame(0.7, $calls[0]['temperature'] ?? null, 'and the configured temperature is still sent');

    // -----------------------------------------------------------------------
    $t->section('Any other error still fails the test');

    $usePlatform(new InMemoryPlatform(static function (): string {
        throw new RuntimeException('Incorrect API key provided');
    }));

    $error = null;
    try {
        $service->testConnection($profileId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $t->assertSame('Incorrect API key provided', $error, 'a wrong key is reported, not swallowed by the fallback');
} finally {
    $removeProfile();
}

$check = rex_sql::factory();
$check->setQuery('SELECT id FROM ' . rex::getTable('ai_profile') . ' WHERE id = ?', [$profileId]);
$t->assertSame(0, $check->getRows(), 'fixture profile removed');

exit($t->summary());
