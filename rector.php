<?php

declare(strict_types=1);

// Automated refactoring configuration only — no bundle logic lives here.

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpSets(php84: true)
    ->withImportNames(removeUnusedImports: true);
