<?php

declare(strict_types=1);

rex_sql_table::get(rex::getTable('ai_profile'))->drop();

rex_config::removeNamespace('ai_platform');
