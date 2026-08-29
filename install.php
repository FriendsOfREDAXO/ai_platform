<?php

declare(strict_types=1);

// AI profiles (LLM provider config)
rex_sql_table::get(rex::getTable('ai_profile'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('name', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('type', 'varchar(50)', false, 'text', null, 'text, image_generation, image_understanding, embedding'))
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
// runs through OAuth 2.1 (see FriendsOfRedaxo\AiPlatform\Mcp\Authenticator) —
// public tools keep working without any token, protected tools require an
// OAuth flow.
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

// ---------------------------------------------------------------------------
// Change requests: proposals from agents and addons, applied only after an
// editor approves them. See lib/Change/.
// ---------------------------------------------------------------------------

rex_sql_table::get(rex::getTable('ai_change_request'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('changeset_id', 'int(11)', true, null, null, 'package this request belongs to'))
    ->ensureColumn(new rex_sql_column('type', 'varchar(64)', false, null, null, 'handler type: slice, article, category, meta, media, yform'))
    ->ensureColumn(new rex_sql_column('operation', 'varchar(20)', false, null, null, 'create, update, delete'))
    ->ensureColumn(new rex_sql_column('target', 'text', false, null, null, 'JSON target coordinates'))
    ->ensureColumn(new rex_sql_column('target_label', 'varchar(255)', false, '', null, 'denormalised label, survives target deletion'))
    ->ensureColumn(new rex_sql_column('payload', 'mediumtext', false, null, null, 'JSON values as proposed'))
    ->ensureColumn(new rex_sql_column('payload_edited', 'mediumtext', true, null, null, 'JSON values after reviewer correction'))
    ->ensureColumn(new rex_sql_column('snapshot_before', 'mediumtext', true, null, null, 'JSON state at proposal time'))
    ->ensureColumn(new rex_sql_column('base_hash', 'varchar(64)', false, '', null, 'fingerprint of snapshot_before, drives stale detection'))
    ->ensureColumn(new rex_sql_column('context_snapshot', 'text', true, null, null, 'JSON surroundings at proposal time (siblings, container state)'))
    ->ensureColumn(new rex_sql_column('context_hash', 'varchar(64)', true, null, null, 'fingerprint of context_snapshot; detects a changed environment, which matters for create where there is no target to compare'))
    ->ensureColumn(new rex_sql_column('reason', 'text', true, null, null, 'why the source proposes this'))
    ->ensureColumn(new rex_sql_column('status', 'varchar(20)', false, 'pending', null, 'pending, approved, applied, rejected, failed, superseded, expired'))
    ->ensureColumn(new rex_sql_column('source_channel', 'varchar(32)', false, 'php', null, 'php, agent, backend, custom'))
    ->ensureColumn(new rex_sql_column('source_key', 'varchar(100)', false, '', null, 'stable identifier of the submitting party'))
    ->ensureColumn(new rex_sql_column('source_label', 'varchar(255)', false, '', null, 'display name'))
    ->ensureColumn(new rex_sql_column('source_user_type', 'varchar(10)', true, null, null, 'rex or ycom'))
    ->ensureColumn(new rex_sql_column('source_user_id', 'int(10) unsigned', true))
    ->ensureColumn(new rex_sql_column('source_meta', 'text', true, null, null, 'JSON: model, profile id, agent run'))
    ->ensureColumn(new rex_sql_column('reviewed_by', 'int(10) unsigned', true, null, null, 'rex_user id of the reviewer'))
    ->ensureColumn(new rex_sql_column('reviewed_at', 'datetime', true))
    // How the decision was reached, as opposed to what was decided. Kept apart
    // from `status` on purpose: status answers what happened to the request,
    // this answers through which channel someone decided it. Folding the two
    // together would mean a new status value per channel, and every "is this
    // still open?" query would have to learn about each one.
    ->ensureColumn(new rex_sql_column('reviewed_via', 'varchar(20)', true, null, null, 'backend | api | auto'))
    ->ensureColumn(new rex_sql_column('review_note', 'text', true))
    ->ensureColumn(new rex_sql_column('applied_at', 'datetime', true))
    ->ensureColumn(new rex_sql_column('apply_result', 'text', true, null, null, 'JSON: created ids, messages'))
    ->ensureColumn(new rex_sql_column('apply_error', 'text', true))
    ->ensureGlobalColumns()
    ->ensureIndex(new rex_sql_index('status_created', ['status', 'createdate']))
    ->ensureIndex(new rex_sql_index('type', ['type']))
    ->ensureIndex(new rex_sql_index('changeset', ['changeset_id']))
    ->ensureIndex(new rex_sql_index('source', ['source_key']))
    ->ensure();

// Packages of change requests. `token` is server-generated and referenced
// everywhere; `client_key` is the submitter's own name for the package, so it
// can keep appending across calls. Unique per source, so two sources can use
// the same friendly name without colliding.
rex_sql_table::get(rex::getTable('ai_changeset'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('token', 'varchar(64)'))
    ->ensureColumn(new rex_sql_column('client_key', 'varchar(100)', true))
    ->ensureColumn(new rex_sql_column('title', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('description', 'text', true))
    ->ensureColumn(new rex_sql_column('status', 'varchar(20)', false, 'open', null, 'open, applied, partially_applied, rejected'))
    ->ensureColumn(new rex_sql_column('source_channel', 'varchar(32)', false, 'php'))
    ->ensureColumn(new rex_sql_column('source_key', 'varchar(100)', false, ''))
    ->ensureColumn(new rex_sql_column('source_label', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('source_user_type', 'varchar(10)', true))
    ->ensureColumn(new rex_sql_column('source_user_id', 'int(10) unsigned', true))
    ->ensureColumn(new rex_sql_column('source_meta', 'text', true))
    ->ensureGlobalColumns()
    ->ensureIndex(new rex_sql_index('token', ['token'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('source_client_key', ['source_key', 'client_key'], rex_sql_index::UNIQUE))
    ->ensure();

// Staged media files: bytes offered for the media pool, waiting for a decision.
//
// The file itself never goes in here — it sits under
// `data/addons/ai_platform/pending/`, which `redaxo/data/.htaccess` denies to the
// web. This table is the reservation and the bookkeeping.
//
// Two unique indexes, both load-bearing:
//
// - `handle` is what a proposal points at.
// - `filename` is the media pool name this upload has **reserved**. Without the
//   reservation the final name would only be decided at approval time, because
//   `rex_mediapool::filename()` subindexes on collision (`bild_2.jpg`) — and an
//   agent that cannot know the name in advance cannot reference the image from a
//   slice proposal, since `SliceHandler::checkReferences()` resolves `media1`
//   slots against `rex_media::get()` at submission time. The unique index is the
//   guarantee, not the check in PHP: two agents uploading `bulli.jpg` at the same
//   moment must not both be told they own it.
//
// `request_id` is filled once a proposal references the upload — see
// `PayloadInterface::attachments()`. It is what lets the cronjob tell an
// abandoned upload from one that is waiting on a reviewer, without a LIKE over
// every payload.
rex_sql_table::get(rex::getTable('ai_change_upload'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('handle', 'varchar(40)', false, null, null, 'up_ + 32 hex, what a proposal points at'))
    ->ensureColumn(new rex_sql_column('filename', 'varchar(255)', false, null, null, 'reserved media pool filename'))
    ->ensureColumn(new rex_sql_column('original_name', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('extension', 'varchar(20)', false, ''))
    ->ensureColumn(new rex_sql_column('mime', 'varchar(100)', false, ''))
    ->ensureColumn(new rex_sql_column('bytes', 'int(11)', false, '0'))
    ->ensureColumn(new rex_sql_column('width', 'int(11)', true))
    ->ensureColumn(new rex_sql_column('height', 'int(11)', true))
    ->ensureColumn(new rex_sql_column('source_channel', 'varchar(32)', false, 'php'))
    ->ensureColumn(new rex_sql_column('source_key', 'varchar(100)', false, ''))
    ->ensureColumn(new rex_sql_column('source_label', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('request_id', 'int(11)', true, null, null, 'set once a proposal references this upload'))
    ->ensureColumn(new rex_sql_column('expires_at', 'datetime', true, null, null, 'reservation deadline while no proposal references it'))
    ->ensureColumn(new rex_sql_column('consumed_at', 'datetime', true, null, null, 'when the media pool file was actually created'))
    ->ensureGlobalColumns()
    ->ensureIndex(new rex_sql_index('handle', ['handle'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('filename', ['filename'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('request_id', ['request_id']))
    ->ensureIndex(new rex_sql_index('expires_at', ['expires_at']))
    ->ensure();

// Change request defaults.
//
// changes_allowed_yform_tables defaults to an empty list meaning NO table is
// writable — the opposite of the module list. A YForm proposal can name any
// table, so an empty allow list must be a closed door, not an open one.
// Approval over the REST API was briefly configurable — an on switch plus an
// allow list for types, operations, a category fence and a batch cap. All of it
// is gone: the scope on the token is the decision, and a second switch asking
// the same question only creates a state where the two disagree (scope granted,
// feature "off", 403 that reads like a bug). The keys are actively removed so an
// installation that saw the earlier version does not carry dead config that
// looks meaningful.
foreach ([
    'changes_api_approve_enabled',
    'changes_api_approve_types',
    'changes_api_approve_operations',
    'changes_api_approve_root',
    'changes_api_approve_offline_only',
    'changes_api_approve_batch_limit',
    // Removed later: limits that never limited the thing that mattered, and a
    // module allow list replaced by reading the module's own output. See
    // pages/ai_changes.settings.php for the reasoning on each.
    'changes_api_batch_limit',
    'changes_batch_limit',
    'changes_max_payload_kb',
    'changes_max_open_per_source',
    'changes_retention_days',
    'changes_allowed_modules',
    'changes_allow_all_modules',
] as $abandoned) {
    if (rex_config::has('ai_platform', $abandoned)) {
        rex_config::remove('ai_platform', $abandoned);
    }
}

$changeDefaults = [
    'changes_enabled' => 1,
    'changes_stale_policy' => 'block',
    'changes_allowed_yform_tables' => '',
    // YForm tables default to closed, and that asymmetry with everything else is
    // the point: a proposal can name any table in the database, so an empty list
    // has to be a shut door rather than an open one.
    'changes_allow_all_yform_tables' => 0,
];

foreach ($changeDefaults as $key => $value) {
    if (!rex_config::has('ai_platform', $key)) {
        rex_config::set('ai_platform', $key, $value);
    }
}
