<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform;

use FriendsOfRedaxo\AiPlatform\Mcp\McpEndpoint;
use Override;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Addon\OwnedSchema;
use Redaxo\Core\Backend\Controller;
use Redaxo\Core\Backend\MainPage;
use Redaxo\Core\Backend\Page;
use Redaxo\Core\Core;
use Redaxo\Core\Database\Column;
use Redaxo\Core\Database\Index;
use Redaxo\Core\Database\Table;
use Redaxo\Core\View\Asset;

/**
 * LLM-Profile für REDAXO 6 auf Symfony AI.
 *
 * Ein Profil ist ein Anwendungsfall: Typ (Text, Embedding, Bilderzeugung, Bildverständnis), Anbieter, Modell und die
 * Optionen des Typs. Die API-Schlüssel stehen nicht in der Datenbank, sondern in Umgebungsvariablen (`.env.local`),
 * genau wie `DATABASE_URL` und `MAILER_DSN`: ein Datenbank-Dump, der zwischen Live, Staging und lokal wandert, trägt
 * so keinen Schlüssel mit, und jede Instanz hat ihren eigenen. Das Profil nennt nur den Namen der Variable.
 */
final class AiPlatformAddon extends Addon
{
    public const string TABLE_PROFILE = Core::TABLE_PREFIX . 'ai_profile';

    /** @var array<string, mixed> */
    public protected(set) array $defaultConfig = [
        'default_text_profile' => 0,
        'default_embedding_profile' => 0,
        'default_image_generation_profile' => 0,
        'default_image_understanding_profile' => 0,
        // Fenster ohne Antwort in Sekunden; eine lange, nicht gestreamte Antwort sendet nichts, bis sie fertig ist.
        'http_timeout' => 300,
        // Gesamtdauer in Sekunden, 0 = unbegrenzt.
        'http_max_duration' => 0,
        'mcp_enabled' => false,
    ];

    #[Override]
    public function boot(): void
    {
        McpEndpoint::register();

        if (Core::isBackend() && 'ai_platform' === Controller::getCurrentPagePart(1)) {
            Asset::addCssFile($this->getAssetsUrl('styles.css'));
            Asset::addJsFile($this->getAssetsUrl('profiles.js'));
        }
    }

    #[Override]
    public function install(): void
    {
        Table::get(self::TABLE_PROFILE)
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Column('name', 'varchar(191)'))
            ->ensureColumn(new Column('type', 'varchar(50)', false, 'text'))
            ->ensureColumn(new Column('provider', 'varchar(50)'))
            // Name der Umgebungsvariable mit dem API-Schlüssel; leer = AI_PLATFORM_<ANBIETER>_API_KEY.
            ->ensureColumn(new Column('api_key_env', 'varchar(100)', true))
            ->ensureColumn(new Column('base_url', 'varchar(500)', true))
            ->ensureColumn(new Column('model', 'varchar(255)', false, ''))
            ->ensureColumn(new Column('temperature', 'decimal(3,2)', true))
            ->ensureColumn(new Column('max_tokens', 'int(10) unsigned', true))
            ->ensureColumn(new Column('system_prompt', 'text', true))
            ->ensureColumn(new Column('image_size', 'varchar(50)', true))
            ->ensureColumn(new Column('image_quality', 'varchar(20)', true))
            ->ensureColumn(new Column('image_style', 'varchar(20)', true))
            ->ensureColumn(new Column('detail_level', 'varchar(20)', true))
            ->ensureColumn(new Column('status', 'tinyint(1)', false, '1'))
            ->ensureGlobalColumns()
            ->ensureIndex(new Index('name', ['name'], Index::UNIQUE))
            ->ensure();
    }

    #[Override]
    public function getOwnedSchema(): OwnedSchema
    {
        return new OwnedSchema(tables: [self::TABLE_PROFILE]);
    }

    /** @return iterable<Page> */
    #[Override]
    public function getPages(): iterable
    {
        $main = new MainPage('addons', 'ai_platform', $this->i18n('title'));
        $main->setRequiredPermissions('admin');
        $main->setIcon('rex-icon fa-brain');
        $main->setPath($this->getPath('pages/index.php'));

        $main->addSubpage(new Page('profiles', $this->i18n('profiles'))->setSubPath($this->getPath('pages/profiles.php')));
        $main->addSubpage(new Page('mcp', $this->i18n('mcp'))->setSubPath($this->getPath('pages/mcp.php')));
        $main->addSubpage(
            new Page('settings', $this->i18n('settings'))
                ->setIcon('rex-icon fa-cogs')
                ->setItemAttr('data-align', 'end')
                ->setSubPath($this->getPath('pages/settings.php')),
        );
        $main->addSubpage(
            new Page('docs', $this->i18n('docs'))
                ->setIcon('rex-icon fa-book')
                ->setItemAttr('data-align', 'end')
                ->setSubPath($this->getPath('README.md')),
        );

        yield $main;
    }
}
