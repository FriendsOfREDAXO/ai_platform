<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Redaxo\Core\ClassDiscovery;
use Redaxo\Core\ExtensionPoint\Extension;
use Redaxo\Core\ExtensionPoint\ExtensionPoint;
use ReflectionMethod;

use function is_array;

/**
 * Die Tools des MCP-Servers.
 *
 * Ein Tool ist eine Methode mit dem Attribut `#[McpTool]` des offiziellen SDK, irgendwo im Projekt (`src/`) oder in
 * einem AddOn -- gefunden über die Class Discovery des Core, wie Module, Cronjobs und API-Funktionen. Das SDK leitet
 * Name, Beschreibung und Eingabeschema aus Methode, Docblock und Parametertypen ab:
 *
 *     final class Opening
 *     {
 *         /** Öffnungszeiten eines Standorts. *\/
 *         #[McpTool(name: 'opening_hours')]
 *         public static function hours(string $location): array { … }
 *     }
 *
 * Für Tools, die erst zur Laufzeit feststehen, ergänzt der Extension Point `AI_PLATFORM_MCP_TOOLS` die Liste; jeder
 * Eintrag sind die benannten Argumente von `Mcp\Server\Builder::addTool()` (mindestens `handler` und `name`).
 *
 * Attribut-Argumente legt die Discovery als exportierten PHP-Code im Cache ab; Objekte darin (`new ToolAnnotations()`)
 * lassen sich so nicht ablegen. Hinweise wie `readOnlyHint` kommen deshalb über den Extension Point, dort auch als
 * Array (`'annotations' => ['readOnlyHint' => true]`).
 */
final class ToolRegistry
{
    public const string EXTENSION_POINT = 'AI_PLATFORM_MCP_TOOLS';

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $tools = [];
        foreach (ClassDiscovery::getInstance()->discoverByMethodAttribute(McpTool::class) as $entry) {
            /** @var McpTool $attribute */
            $attribute = $entry['attribute'];
            $tools[] = array_filter([
                'handler' => [$entry['class'], $entry['method']],
                'name' => $attribute->name ?? $entry['method'],
                'title' => $attribute->title,
                'description' => $attribute->description ?? self::docSummary($entry['class'], $entry['method']),
                'annotations' => $attribute->annotations,
                'icons' => $attribute->icons,
                'meta' => $attribute->meta,
                'outputSchema' => $attribute->outputSchema,
            ], static fn (mixed $value): bool => null !== $value);
        }

        /** @var list<array<string, mixed>> $tools */
        $tools = Extension::dispatch(new ExtensionPoint(self::EXTENSION_POINT, $tools));

        foreach ($tools as $i => $tool) {
            if (isset($tool['annotations']) && is_array($tool['annotations'])) {
                $tools[$i]['annotations'] = ToolAnnotations::fromArray($tool['annotations']);
            }
        }

        return $tools;
    }

    /**
     * Der Beschreibungstext des Docblocks, ohne Tags -- wie ihn das SDK selbst ableiten würde, hier schon vorab, damit
     * die Backend-Seite dieselbe Beschreibung zeigt, die ein Client bekommt.
     *
     * @param class-string $class
     */
    private static function docSummary(string $class, string $method): ?string
    {
        $doc = (string) new ReflectionMethod($class, $method)->getDocComment();
        $lines = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim((string) preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line));
            if (str_starts_with($line, '@')) {
                break;
            }
            $lines[] = $line;
        }
        $text = trim((string) preg_replace('/[ \t]*\n[ \t]*/', ' ', trim(implode("\n", $lines))));

        return '' === $text ? null : $text;
    }
}
