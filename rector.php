<?php
use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use Rector\Php54\Rector\Array_\LongArrayToShortArrayRector;
use Rector\Php71\Rector\List_\ListToArrayDestructRector;
use Rector\Php80\Rector\Catch_\RemoveUnusedVariableInCatchRector;

return RectorConfig::configure()
  ->withPhpVersion(PhpVersion::PHP_82)
  ->withPaths([
    __DIR__ . '/CRM',
    __DIR__ . '/Civi',
    __DIR__ . '/cmcic.php',
  ])
  ->withRules([
    LongArrayToShortArrayRector::class,
    ListToArrayDestructRector::class,
    RemoveUnusedVariableInCatchRector::class,
  ]);
