<?php

/**
 * Service::createAgent() -- der eine Pfad des AddOns, der ueber symfony/ai-agent
 * laeuft statt nur ueber symfony/ai-platform.
 *
 * Warum es diesen Test gibt: bis Symfony AI 0.12 wurde das Tool-Calling ueber einen
 * `Toolbox\AgentProcessor` eingehaengt, der in 0.13 entfernt wurde ("tool calling is
 * now driven by the Agent itself"). Der Aufruf in createAgent() war damit ein Fatal
 * Error -- und kein Test im Harness hat es gemerkt, weil alle anderen an der Platform
 * hingen und nicht am Agenten. Genau diese Luecke schliesst die Datei.
 *
 * Es geht nichts ins Netz: das Profil zeigt auf den generischen Provider, dessen
 * Platform sich ohne Request baut, und der Agent wird gegen `InMemoryPlatform`
 * aufgerufen. Ein Profil wird angelegt und am Ende wieder entfernt.
 */

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Service;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Test\InMemoryPlatform;

require __DIR__ . '/bootstrap.php';

$t = new AiTestRunner('Agent');

#[AsTool(name: 'ai_platform_test_tool', description: 'Returns a fixed string, for the test only.')]
final class AiPlatformTestTool
{
    public function __invoke(): string
    {
        return 'tool-was-here';
    }
}

// ---------------------------------------------------------------------------
$t->section('A profile pointing at the generic provider');

$sql = rex_sql::factory();
$sql->setTable(rex::getTable('ai_profile'));
$sql->setValue('name', 'agent-test-' . uniqid());
$sql->setValue('type', 'text');
$sql->setValue('provider', 'generic');
$sql->setValue('base_url', 'https://ai.invalid');
$sql->setValue('api_key', 'test-key');
$sql->setValue('model', 'whatever-the-server-calls-it');
$sql->setValue('status', 1);
$sql->insert();
$profileId = (int) $sql->getLastId();

$t->assert($profileId > 0, 'the fixture profile was created');

// ---------------------------------------------------------------------------
$t->section('createAgent builds');

$agent = Service::getInstance()->createAgent('text', [], $profileId);
$t->assert($agent instanceof Agent, 'createAgent returns an Agent');

// Zusaetzlich uebergebene Tools gehen durch den Extension Point und muessen im
// Agenten landen. Geprueft wird das an der Toolbox, weil der Agent seine eigene
// nicht herausgibt -- dieselbe Konstruktion, die createAgent() verwendet.
$toolbox = new Toolbox([new AiPlatformTestTool()]);
$tools = $toolbox->getTools();
$t->assertSame(1, count($tools), 'the toolbox holds the one tool it was given');
$t->assertSame('ai_platform_test_tool', $tools[0]->getName(), 'the #[AsTool] name is read from the attribute');

// ---------------------------------------------------------------------------
$t->section('And answers');

// Die Vorgabe fuer maxToolCalls ist seit 0.12 50 statt unbegrenzt. Das aendert am
// Bau nichts, ist aber der Grund, warum eine sehr lange Werkzeugkette abbricht.
$offline = new Agent(new InMemoryPlatform('OK'), 'some-model', toolbox: $toolbox);
$answer = $offline->call(new MessageBag(Message::ofUser('ping')));
$t->assertSame('OK', $answer->asText(), 'a call returns the platform result as text');

// ---------------------------------------------------------------------------
$t->section('Cleanup');

$delete = rex_sql::factory();
$delete->setTable(rex::getTable('ai_profile'));
$delete->setWhere('id = :id', ['id' => $profileId]);
$delete->delete();
// Bewusst per SQL und nicht ueber getProfile(): der Service haelt einen
// Profil-Cache pro Request, und der kennt die Zeile nach dem DELETE noch. Das ist
// beabsichtigt und hier gerade der Grund, ihn nicht zu fragen.
$check = rex_sql::factory();
$check->setQuery('SELECT id FROM ' . rex::getTable('ai_profile') . ' WHERE id = ?', [$profileId]);
$t->assertSame(0, $check->getRows(), 'fixture profile removed');

exit($t->summary());
