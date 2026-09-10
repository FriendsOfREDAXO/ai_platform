<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Target;

use FriendsOfRedaxo\AiPlatform\Change\AbstractTarget;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use InvalidArgumentException;
use rex;
use rex_category;
use rex_clang;
use rex_i18n;
use rex_media;
use rex_url;

/**
 * Points at the metainfo values of one carrier.
 *
 * All four carriers share one target class because the mechanism is
 * identical — a column patch on the carrier's own table — and differs only
 * in the field prefix and how the row is addressed. Splitting them would
 * duplicate the whitelist logic four times.
 *
 * The operation is always Update: metainfo values exist as soon as their
 * carrier exists, so there is nothing to create or delete here. Adding or
 * removing field *definitions* is a schema change and deliberately out of
 * scope for editorial change requests.
 */
final class MetaTarget extends AbstractTarget
{
    public const CARRIER_ARTICLE = 'article';
    public const CARRIER_CATEGORY = 'category';
    public const CARRIER_MEDIA = 'media';
    public const CARRIER_CLANG = 'clang';

    /**
     * Field prefix per carrier, matching the metainfo addon's convention.
     */
    private const PREFIXES = [
        self::CARRIER_ARTICLE => 'art_',
        self::CARRIER_CATEGORY => 'cat_',
        self::CARRIER_MEDIA => 'med_',
        self::CARRIER_CLANG => 'clang_',
    ];

    private function __construct(
        private readonly string $carrier,
        private readonly ?int $id = null,
        private readonly ?int $clangId = null,
        private readonly ?string $filename = null,
    ) {
    }

    public static function changeType(): string
    {
        return 'meta';
    }

    public static function article(int $articleId, int $clangId): self
    {
        return new self(
            self::CARRIER_ARTICLE,
            self::assertPositive($articleId, 'Article id'),
            self::assertClang($clangId),
        );
    }

    public static function category(int $categoryId, int $clangId): self
    {
        return new self(
            self::CARRIER_CATEGORY,
            self::assertPositive($categoryId, 'Category id'),
            self::assertClang($clangId),
        );
    }

    public static function media(string $filename): self
    {
        if ('' === trim($filename)) {
            throw new InvalidArgumentException('Media filename must not be empty.');
        }

        return new self(self::CARRIER_MEDIA, null, null, $filename);
    }

    public static function clang(int $clangId): self
    {
        return new self(self::CARRIER_CLANG, self::assertClang($clangId), $clangId);
    }

    public function operation(): ChangeOperation
    {
        return ChangeOperation::Update;
    }

    public function getCarrier(): string
    {
        return $this->carrier;
    }

    /**
     * The `art_` / `cat_` / `med_` / `clang_` prefix every field in the
     * payload has to carry.
     */
    public function getFieldPrefix(): string
    {
        return self::PREFIXES[$this->carrier];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClangId(): ?int
    {
        return $this->clangId;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    /**
     * Table the values are stored in. Articles and categories share
     * rex_article — that is REDAXO's data model, not a shortcut.
     */
    public function getTable(): string
    {
        return match ($this->carrier) {
            self::CARRIER_ARTICLE, self::CARRIER_CATEGORY => rex::getTable('article'),
            self::CARRIER_MEDIA => rex::getTable('media'),
            self::CARRIER_CLANG => rex::getTable('clang'),
        };
    }

    /**
     * WHERE clause identifying the carrier row, as [sql, params].
     *
     * @return array{string, array<string, scalar>}
     */
    public function getRowCondition(): array
    {
        return match ($this->carrier) {
            self::CARRIER_ARTICLE, self::CARRIER_CATEGORY => [
                'id = :id AND clang_id = :clang',
                [':id' => (int) $this->id, ':clang' => (int) $this->clangId],
            ],
            self::CARRIER_MEDIA => [
                'filename = :filename',
                [':filename' => (string) $this->filename],
            ],
            self::CARRIER_CLANG => [
                'id = :id',
                [':id' => (int) $this->id],
            ],
        };
    }

    public function toArray(): array
    {
        return [
            'carrier' => $this->carrier,
            'id' => $this->id,
            'clang_id' => $this->clangId,
            'filename' => $this->filename,
        ];
    }

    public static function fromArray(array $data): static
    {
        $carrier = self::requireString($data, 'carrier');
        if (!isset(self::PREFIXES[$carrier])) {
            throw new InvalidArgumentException(sprintf('Unknown metainfo carrier "%s".', $carrier));
        }

        $filename = $data['filename'] ?? null;

        return new self(
            $carrier,
            self::optionalInt($data, 'id'),
            self::optionalInt($data, 'clang_id'),
            is_string($filename) && '' !== $filename ? $filename : null,
        );
    }

    public function describe(): string
    {
        $label = rex_i18n::rawMsg('ai_platform_change_target_meta') . ' · ';

        return $label . match ($this->carrier) {
            self::CARRIER_ARTICLE => rex_i18n::rawMsg('ai_platform_change_target_article') . ' ' . self::describeArticle((int) $this->id, (int) $this->clangId),
            self::CARRIER_CATEGORY => rex_i18n::rawMsg('ai_platform_change_target_category') . ' ' . $this->describeCategory(),
            self::CARRIER_MEDIA => rex_i18n::rawMsg('ai_platform_change_target_media') . ' »' . (string) $this->filename . '«',
            self::CARRIER_CLANG => rex_i18n::rawMsg('ai_platform_change_target_clang') . ' ' . (rex_clang::get((int) $this->id)?->getName() ?? '#' . (int) $this->id),
        };
    }

    private function describeCategory(): string
    {
        $category = rex_category::get((int) $this->id, (int) $this->clangId);
        $name = null === $category ? '' : sprintf(' »%s«', $category->getName());
        $clang = rex_clang::get((int) $this->clangId);

        return sprintf('#%d%s%s', (int) $this->id, $name, null === $clang ? '' : ' (' . $clang->getCode() . ')');
    }

    public function backendUrl(): ?string
    {
        return match ($this->carrier) {
            self::CARRIER_ARTICLE => rex_url::backendPage('content/functions', [
                'article_id' => (int) $this->id,
                'clang' => (int) $this->clangId,
            ]),
            self::CARRIER_CATEGORY => rex_url::backendPage('structure', [
                'category_id' => (int) $this->id,
                'clang' => (int) $this->clangId,
                'edit_id' => (int) $this->id,
                'function' => 'edit_cat',
            ]),
            self::CARRIER_MEDIA => null === rex_media::get((string) $this->filename) ? null : rex_url::backendPage('mediapool/media', [
                'file_name' => (string) $this->filename,
            ]),
            self::CARRIER_CLANG => rex_url::backendPage('system/lang'),
        };
    }
}
