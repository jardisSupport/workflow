<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Smoke suite: the generat namespaces (copies of the builder goldens and their stubs) resolve to
// tests/Smoke, never through a composer.json of a consumer (see README "Testing against generated code").
$smoke = __DIR__ . '/Smoke';
$loaders = \Composer\Autoload\ClassLoader::getRegisteredLoaders();
$loader = reset($loaders);
if ($loader instanceof \Composer\Autoload\ClassLoader) {
    $loader->addPsr4('Ecommerce\\Sales\\Process\\OrderBulkQuote\\', [
        $smoke . '/Fixture/OrderBulkQuote',
        $smoke . '/Support/Stub/Ecommerce/Sales/Process/OrderBulkQuote',
    ]);
    $loader->addPsr4('Ops\\Registry\\Process\\RegisterAndReviseServiceOptOut\\', [
        $smoke . '/Fixture/RegisterAndReviseServiceOptOut',
        $smoke . '/Support/Stub/Ops/Registry/Process/RegisterAndReviseServiceOptOut',
    ]);
    $loader->addPsr4('Ecommerce\\', $smoke . '/Support/Stub/Ecommerce');
    $loader->addPsr4('Ops\\', $smoke . '/Support/Stub/Ops');
}
