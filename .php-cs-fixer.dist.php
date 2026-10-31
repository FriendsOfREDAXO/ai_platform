<?php

declare(strict_types=1);

// Ausführen mit dem php-cs-fixer des REDAXO-6-Core (dort liegt redaxo/php-cs-fixer-config):
// ../redaxo6/vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/pages'])
;

return Redaxo\PhpCsFixerConfig\Config::redaxo6()
    ->setFinder($finder)
;
