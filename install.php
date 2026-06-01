<?php

declare(strict_types=1);

rex_sql_table::get(rex::getTable('ai_profile'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('name', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('type', 'varchar(50)', false, 'text', null, 'text, image_generation, image_understanding'))
    ->ensureColumn(new rex_sql_column('provider', 'varchar(50)', false, null, null, 'openai, anthropic, google, ollama'))
    ->ensureColumn(new rex_sql_column('api_key', 'text', true))
    ->ensureColumn(new rex_sql_column('base_url', 'varchar(500)', true))
    ->ensureColumn(new rex_sql_column('model', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('temperature', 'decimal(3,2)', true))
    ->ensureColumn(new rex_sql_column('max_tokens', 'int(10) unsigned', true))
    ->ensureColumn(new rex_sql_column('system_prompt', 'text', true))
    ->ensureColumn(new rex_sql_column('image_size', 'varchar(50)', true))
    ->ensureColumn(new rex_sql_column('image_quality', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('image_style', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('detail_level', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('status', 'tinyint(1)', false, '1'))
    ->ensureGlobalColumns()
    ->removeColumn('model_text')
    ->removeColumn('model_image_generation')
    ->removeColumn('model_image_understanding')
    ->ensureIndex(new rex_sql_index('name', ['name'], rex_sql_index::UNIQUE))
    ->ensure();

// Set default config values if not yet set
if (!rex_config::has('ai_platform', 'default_text_profile')) {
    rex_config::set('ai_platform', 'default_text_profile', 0);
}
if (!rex_config::has('ai_platform', 'default_image_generation_profile')) {
    rex_config::set('ai_platform', 'default_image_generation_profile', 0);
}
if (!rex_config::has('ai_platform', 'default_image_understanding_profile')) {
    rex_config::set('ai_platform', 'default_image_understanding_profile', 0);
}
if (!rex_config::has('ai_platform', 'mcp_enabled')) {
    rex_config::set('ai_platform', 'mcp_enabled', 0);
}

// Cleanup obsolete static bearer token from pre-1.0 installs. Auth now
// runs through OAuth 2.1 (see rex_ai_mcp_authenticator) — public tools
// keep working without any token, protected tools require an OAuth flow.
rex_config::remove('ai_platform', 'mcp_token');
