<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Handler;

use FriendsOfRedaxo\AiPlatform\Change\AbstractHandler;
use FriendsOfRedaxo\AiPlatform\Change\ApplyResult;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequest;
use FriendsOfRedaxo\AiPlatform\Change\Diff\DiffField;
use FriendsOfRedaxo\AiPlatform\Change\Payload\MetaPayload;
use FriendsOfRedaxo\AiPlatform\Change\Support\MetaFieldRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Target\MetaTarget;
use FriendsOfRedaxo\AiPlatform\Change\TargetInterface;
use FriendsOfRedaxo\AiPlatform\Change\ValidationException;
use rex;
use rex_article;
use rex_article_cache;
use rex_clang;
use rex_extension;
use rex_extension_point;
use rex_i18n;
use rex_media;
use rex_media_cache;
use rex_metainfo_default_type;
use rex_sql;
use rex_user;
use Throwable;

use function array_key_exists;
use function str_starts_with;

/**
 * Metainfo values for articles, categories, media files and languages.
 *
 * One handler for all four carriers, because the write is the same in each
 * case — a column patch on the carrier's own table, followed by the cache
 * invalidation and extension point that carrier expects. The field whitelist
 * comes from MetaFieldRegistry, which reads the real metainfo definitions, so
 * a payload can only ever name a field that exists.
 *
 * Only updates exist here: metainfo values come into being with their
 * carrier. Adding or removing field *definitions* is a schema change and
 * deliberately not something an editorial change request can do.
 */
final class MetaHandler extends AbstractHandler
{
    public function getType(): string
    {
        return 'meta';
    }

    public function targetClass(): string
    {
        return MetaTarget::class;
    }

    public function payloadClass(): string
    {
        return MetaPayload::class;
    }

    public function supportedOperations(): array
    {
        return [ChangeOperation::Update];
    }

    public function readCurrent(TargetInterface $target): ?array
    {
        $target = $this->requireTarget($target);
        $fields = MetaFieldRegistry::forPrefix($target->getFieldPrefix());
        if ([] === $fields) {
            // No metainfo fields for this carrier — nothing to read, and
            // nothing that could be written either.
            return null;
        }

        if (!$this->carrierExists($target)) {
            return null;
        }

        [$where, $params] = $target->getRowCondition();
        $columns = implode(', ', array_map(static fn(string $name): string => '`' . $name . '`', array_keys($fields)));

        $rows = rex_sql::factory()->getArray(
            'SELECT ' . $columns . ' FROM ' . $target->getTable() . ' WHERE ' . $where,
            $params,
        );

        if ([] === $rows) {
            return null;
        }

        $values = [];
        foreach (array_keys($fields) as $name) {
            $values[$name] = self::stringify($rows[0][$name] ?? null);
        }

        return $values;
    }

    /**
     * Media files and articles referenced by widget-type metainfo fields.
     *
     * A `med_`/`art_` field of type REX_MEDIA_WIDGET holds a filename; one of
     * type REX_LINK_WIDGET holds an article id. Either can be gone by the time a
     * proposal is approved, and writing it anyway leaves a dangling reference
     * that only shows up on the website.
     *
     * @return list<string>
     */
    public function checkReferences(ChangeRequest $request): array
    {
        $target = $this->requireTarget($request->target);
        $fields = MetaFieldRegistry::forPrefix($target->getFieldPrefix());
        $clangId = $target->getClangId() ?? rex_clang::getStartId();
        $problems = [];

        foreach ($request->effectivePayload()->toArray() as $name => $value) {
            $name = (string) $name;
            $definition = $fields[$name] ?? null;
            if (null === $definition) {
                continue;
            }

            $text = self::stringify($value);
            if (null === $text || '' === trim($text)) {
                continue;
            }

            switch ($definition['type_id']) {
                case rex_metainfo_default_type::REX_MEDIA_WIDGET:
                    if (null === rex_media::get($text)) {
                        $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_media_gone', $name, $text);
                    }
                    break;

                case rex_metainfo_default_type::REX_MEDIALIST_WIDGET:
                    foreach (explode(',', $text) as $filename) {
                        $filename = trim($filename);
                        if ('' !== $filename && null === rex_media::get($filename)) {
                            $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_media_gone', $name, $filename);
                        }
                    }
                    break;

                case rex_metainfo_default_type::REX_LINK_WIDGET:
                    if (null === rex_article::get((int) $text, $clangId)) {
                        $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_article_gone', $name, $text);
                    }
                    break;

                case rex_metainfo_default_type::REX_LINKLIST_WIDGET:
                    foreach (explode(',', $text) as $articleId) {
                        $articleId = (int) trim($articleId);
                        if ($articleId > 0 && null === rex_article::get($articleId, $clangId)) {
                            $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_article_gone', $name, (string) $articleId);
                        }
                    }
                    break;
            }
        }

        return $problems;
    }

    public function validate(ChangeRequest $request): void
    {
        $target = $this->requireTarget($request->target);
        $payload = $request->effectivePayload()->toArray();

        if ([] === $payload) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_no_fields'));
        }

        if (!$this->carrierExists($target)) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_carrier_gone', $target->describe()));
        }

        $prefix = $target->getFieldPrefix();
        $known = MetaFieldRegistry::forPrefix($prefix);

        foreach (array_keys($payload) as $name) {
            $name = (string) $name;

            if (!str_starts_with($name, $prefix)) {
                throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_meta_prefix', $name, $prefix));
            }

            if (!isset($known[$name])) {
                throw new ValidationException(rex_i18n::rawMsg(
                    'ai_platform_change_err_meta_unknown',
                    $name,
                    [] === $known ? '-' : implode(', ', array_keys($known)),
                ));
            }
        }

        $this->assertReferencesResolve($request);
    }

    public function canApprove(rex_user $user, ChangeRequest $request): bool
    {
        $target = $this->requireTarget($request->target);

        if ($user->isAdmin()) {
            return true;
        }

        return match ($target->getCarrier()) {
            MetaTarget::CARRIER_ARTICLE => $this->canApproveArticle($user, $target),
            MetaTarget::CARRIER_CATEGORY => $this->canApproveCategory($user, $target),
            MetaTarget::CARRIER_MEDIA => $this->canApproveMedia($user, $target),
            // Language metainfo is a system-wide setting; only admins get it.
            MetaTarget::CARRIER_CLANG => false,
            default => false,
        };
    }

    private function canApproveArticle(rex_user $user, MetaTarget $target): bool
    {
        $clangId = (int) $target->getClangId();
        if (!$user->getComplexPerm('clang')->hasPerm($clangId)) {
            return false;
        }

        $article = rex_article::get((int) $target->getId(), $clangId);
        if (null === $article) {
            return false;
        }

        return $user->getComplexPerm('structure')->hasCategoryPerm($article->getCategoryId());
    }

    private function canApproveCategory(rex_user $user, MetaTarget $target): bool
    {
        if (!$user->getComplexPerm('clang')->hasPerm((int) $target->getClangId())) {
            return false;
        }

        // For a category, the permission is checked against the category
        // itself, the way the api addon and the backend do it.
        return $user->getComplexPerm('structure')->hasCategoryPerm((int) $target->getId());
    }

    private function canApproveMedia(rex_user $user, MetaTarget $target): bool
    {
        $media = rex_media::get((string) $target->getFilename());
        if (null === $media) {
            return false;
        }

        return $user->getComplexPerm('media')->hasCategoryPerm($media->getCategoryId());
    }

    /**
     * Uses the metainfo field titles as labels, which is what makes this diff
     * readable — a reviewer sees "SEO-Beschreibung", not "art_seo_description".
     */
    public function diffFields(ChangeRequest $request, ?array $currentValues = null): array
    {
        $target = $this->requireTarget($request->target);
        $fields = MetaFieldRegistry::forPrefix($target->getFieldPrefix());
        $before = $request->snapshotBefore ?? [];

        $diff = [];
        foreach ($request->effectivePayload()->toArray() as $name => $after) {
            $name = (string) $name;
            $definition = $fields[$name] ?? null;
            $label = null === $definition ? $name : rex_i18n::translate($definition['title'], false);

            $beforeValue = array_key_exists($name, $before) ? self::stringify($before[$name]) : null;
            $afterValue = self::stringify($after);
            $currentValue = null === $currentValues ? null : self::stringify($currentValues[$name] ?? null);

            // Multi-value fields are stored pipe-wrapped. Showing "|3|7|" to a
            // reviewer is noise, so they are rendered as a readable list.
            if (null !== $definition && MetaFieldRegistry::isMultiValue($definition)) {
                $beforeValue = self::formatList($beforeValue);
                $afterValue = self::formatList($afterValue);
                $currentValue = null === $currentValue ? null : self::formatList($currentValue);
            }

            $diff[] = DiffField::compare($name, $label, $beforeValue, $afterValue, $currentValue);
        }

        return $diff;
    }

    private static function formatList(?string $stored): ?string
    {
        if (null === $stored) {
            return null;
        }

        $values = MetaFieldRegistry::decodeMultiValue($stored);

        return [] === $values ? '' : implode(', ', $values);
    }

    public function apply(ChangeRequest $request): ApplyResult
    {
        $target = $this->requireTarget($request->target);
        $payload = $request->effectivePayload()->toArray();

        $known = MetaFieldRegistry::forPrefix($target->getFieldPrefix());
        $writable = array_intersect_key($payload, $known);
        if ([] === $writable) {
            return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_err_no_fields'));
        }

        try {
            [$where, $params] = $target->getRowCondition();

            $sql = rex_sql::factory();
            $set = [];
            foreach ($writable as $name => $value) {
                $set[] = '`' . (string) $name . '` = :set_' . (string) $name;
                $params[':set_' . (string) $name] = $value;
            }

            $sql->setQuery(
                'UPDATE ' . $target->getTable() . ' SET ' . implode(', ', $set) . ' WHERE ' . $where,
                $params,
            );

            $touched = $this->invalidateCarrier($target);
        } catch (Throwable $e) {
            return ApplyResult::failed($e->getMessage());
        }

        return ApplyResult::ok([], $touched, [rex_i18n::rawMsg('ai_platform_change_meta_updated')]);
    }

    /**
     * Invalidates the carrier's cache and fires the extension point the
     * corresponding backend page fires.
     *
     * @return list<array{article_id: int, clang_id: int}>
     */
    private function invalidateCarrier(MetaTarget $target): array
    {
        switch ($target->getCarrier()) {
            case MetaTarget::CARRIER_ARTICLE:
                $id = (int) $target->getId();
                $clangId = (int) $target->getClangId();
                rex_article_cache::deleteMeta($id, $clangId);
                rex_extension::registerPoint(new rex_extension_point('ART_META_UPDATED', '', [
                    'id' => $id,
                    'clang' => $clangId,
                ]));

                return [['article_id' => $id, 'clang_id' => $clangId]];

            case MetaTarget::CARRIER_CATEGORY:
                $id = (int) $target->getId();
                $clangId = (int) $target->getClangId();
                rex_article_cache::generateMeta($id, $clangId);

                return [['article_id' => $id, 'clang_id' => $clangId]];

            case MetaTarget::CARRIER_MEDIA:
                rex_media_cache::delete((string) $target->getFilename());

                return [];

            case MetaTarget::CARRIER_CLANG:
                rex_clang::reset();

                return [];
        }

        return [];
    }

    private function carrierExists(MetaTarget $target): bool
    {
        [$where, $params] = $target->getRowCondition();

        $rows = rex_sql::factory()->getArray(
            'SELECT 1 AS found FROM ' . $target->getTable() . ' WHERE ' . $where . ' LIMIT 1',
            $params,
        );

        return [] !== $rows;
    }

    private function requireTarget(TargetInterface $target): MetaTarget
    {
        if (!$target instanceof MetaTarget) {
            throw new ValidationException(sprintf('MetaHandler needs a MetaTarget, got %s.', $target::class));
        }

        return $target;
    }
}
