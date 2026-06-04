<?php

declare(strict_types=1);

// AI profiles (LLM provider config)
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
if (!rex_config::has('ai_platform', 'default_embedding_profile')) {
    rex_config::set('ai_platform', 'default_embedding_profile', 0);
}
if (!rex_config::has('ai_platform', 'mcp_enabled')) {
    rex_config::set('ai_platform', 'mcp_enabled', 0);
}

// Cleanup obsolete static bearer token from pre-1.0 installs. Auth now
// runs through OAuth 2.1 (see rex_ai_mcp_authenticator) — public tools
// keep working without any token, protected tools require an OAuth flow.
rex_config::remove('ai_platform', 'mcp_token');

// OAuth 2.1 client registry (manual + dynamic client registration)
rex_sql_table::get(rex::getTable('ai_oauth_client'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('client_id', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('client_secret_hash', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('client_name', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('redirect_uris', 'text'))
    ->ensureColumn(new rex_sql_column('type', 'varchar(20)', false, 'public', null, 'public, confidential'))
    ->ensureColumn(new rex_sql_column('created_by_dcr', 'tinyint(1)', false, '0'))
    ->ensureColumn(new rex_sql_column('last_used_at', 'datetime', true))
    ->ensureGlobalColumns()
    ->ensureIndex(new rex_sql_index('client_id', ['client_id'], rex_sql_index::UNIQUE))
    ->ensure();

// One-time authorization codes (PKCE flow)
rex_sql_table::get(rex::getTable('ai_oauth_authorization_code'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('code_hash', 'varchar(64)'))
    ->ensureColumn(new rex_sql_column('client_id', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('ycom_user_id', 'int(10) unsigned'))
    ->ensureColumn(new rex_sql_column('scopes', 'text'))
    ->ensureColumn(new rex_sql_column('code_challenge', 'varchar(128)'))
    ->ensureColumn(new rex_sql_column('code_challenge_method', 'varchar(10)', false, 'S256'))
    ->ensureColumn(new rex_sql_column('redirect_uri', 'text'))
    ->ensureColumn(new rex_sql_column('expires_at', 'datetime'))
    ->ensureColumn(new rex_sql_column('used_at', 'datetime', true))
    ->ensureGlobalColumns()
    ->ensureIndex(new rex_sql_index('code_hash', ['code_hash'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('expires_at', ['expires_at']))
    ->ensure();

// Access + refresh tokens issued to clients
rex_sql_table::get(rex::getTable('ai_oauth_token'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('token_hash', 'varchar(64)'))
    ->ensureColumn(new rex_sql_column('type', 'varchar(20)', false, null, null, 'access, refresh'))
    ->ensureColumn(new rex_sql_column('client_id', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('ycom_user_id', 'int(10) unsigned'))
    ->ensureColumn(new rex_sql_column('scopes', 'text'))
    ->ensureColumn(new rex_sql_column('parent_token_id', 'int(11)', true))
    ->ensureColumn(new rex_sql_column('expires_at', 'datetime'))
    ->ensureColumn(new rex_sql_column('revoked_at', 'datetime', true))
    ->ensureGlobalColumns()
    ->ensureIndex(new rex_sql_index('token_hash', ['token_hash'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('type_expires', ['type', 'expires_at']))
    ->ensureIndex(new rex_sql_index('user', ['ycom_user_id']))
    ->ensure();

// YCom group -> scope mapping
rex_sql_table::get(rex::getTable('ai_scope_mapping'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('ycom_group_id', 'int(10) unsigned'))
    ->ensureColumn(new rex_sql_column('scopes', 'text'))
    ->ensureGlobalColumns()
    ->ensureIndex(new rex_sql_index('ycom_group_id', ['ycom_group_id'], rex_sql_index::UNIQUE))
    ->ensure();
