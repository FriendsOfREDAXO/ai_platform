<?php

/** @var Addon $this */

use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Backend\Controller;
use Redaxo\Core\View\View;

echo View::title($this->i18n('title'));

Controller::includeCurrentPageSubPath();
