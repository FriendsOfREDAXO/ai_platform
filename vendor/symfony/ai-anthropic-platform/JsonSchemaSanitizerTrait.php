<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Anthropic;

/**
 * Strips JSON Schema features that Anthropic's structured-output API does not support.
 *
 * @see https://platform.claude.com/docs/en/build-with-claude/structured-outputs#json-schema-limitations
 *
 * @internal
 */
trait JsonSchemaSanitizerTrait
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function normalizeStructuredOutputSchema(array $schema): array
    {
        $unsupported = [];

        foreach (['minimum', 'maximum', 'multipleOf', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'maxItems', 'uniqueItems', 'minContains', 'maxContains'] as $key) {
            if (isset($schema[$key])) {
                $unsupported[$key] = $schema[$key];
                unset($schema[$key]);
            }
        }

        if (isset($schema['minItems']) && $schema['minItems'] > 1) {
            $unsupported['minItems'] = $schema['minItems'];
            unset($schema['minItems']);
        }

        if (isset($schema['additionalProperties']) && false !== $schema['additionalProperties']) {
            $unsupported['additionalProperties'] = $schema['additionalProperties'];
            unset($schema['additionalProperties']);
        }

        if (isset($schema['$ref']) && !str_starts_with((string) $schema['$ref'], '#')) {
            $unsupported['$ref'] = $schema['$ref'];
            unset($schema['$ref']);
        }

        if ([] !== $unsupported) {
            $schema['description'] = (($schema['description'] ?? null) ? $schema['description']."\n\n" : '').json_encode($unsupported, \JSON_UNESCAPED_SLASHES);
        }

        if (isset($schema['properties']) && \is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (\is_array($property)) {
                    $schema['properties'][$key] = $this->normalizeStructuredOutputSchema($property);
                }
            }
        }

        foreach (['anyOf', 'allOf', 'oneOf', 'not'] as $combiner) {
            if (!isset($schema[$combiner]) || !\is_array($schema[$combiner])) {
                continue;
            }

            foreach ($schema[$combiner] as $i => $subSchema) {
                if (\is_array($subSchema)) {
                    $schema[$combiner][$i] = $this->normalizeStructuredOutputSchema($subSchema);
                }
            }
        }

        if (isset($schema['items']) && \is_array($schema['items'])) {
            $schema['items'] = $this->normalizeStructuredOutputSchema($schema['items']);
        }

        return $schema;
    }
}
