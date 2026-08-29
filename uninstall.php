<?php

declare(strict_types=1);

rex_sql_table::get(rex::getTable('ai_profile'))->drop();

// Change requests. Dropped in this order because change_request references a
// changeset — no FK constraint exists, but dropping the referenced table first
// would leave orphan rows readable for the duration of the uninstall.
rex_sql_table::get(rex::getTable('ai_change_request'))->drop();
rex_sql_table::get(rex::getTable('ai_changeset'))->drop();
rex_sql_table::get(rex::getTable('ai_change_upload'))->drop();

// Staged files go with the table that indexed them. Left behind they would be
// unreachable bytes: nothing knows their names any more, and the directory sits
// outside the media pool where no one would think to look.
rex_dir::delete(rex_path::addonData('ai_platform', 'pending/'));

rex_config::removeNamespace('ai_platform');
