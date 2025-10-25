<?php
declare(strict_types=1);

if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

require_once __DIR__ . '/../classes/SyncService.php';

/**
 * Lightweight assertion helper.
 *
 * @param  mixed   $expected  Expected value.
 * @param  mixed   $actual    Actual value.
 * @param  string  $message   Failure message.
 *
 * @return void
 */
function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true)
        );
    }
}

$syncServiceReflection = new ReflectionClass(PlgVmExtendedPrintfulSyncService::class);
$service = $syncServiceReflection->newInstanceWithoutConstructor();

$queueProperty = $syncServiceReflection->getProperty('stockableCombinationQueue');
$queueProperty->setAccessible(true);
$queueProperty->setValue($service, []);

$rememberMethod = $syncServiceReflection->getMethod('rememberStockableCombination');
$rememberMethod->setAccessible(true);

$parentId = 42;

$rememberMethod->invoke($service, $parentId, 501, 'PF-AAA', 'Variant A', 'SKU-A', 12.5, [
    'color' => 'Black',
    'size' => 'M',
]);

$rememberMethod->invoke($service, $parentId, 502, 'PF-BBB', 'Variant B', 'SKU-B', 13.75, [
    'color' => 'Black',
    'size' => 'M',
]);

$rememberMethod->invoke($service, $parentId, 503, '', '', '', 9.5, [
    'color' => 'Black',
    'size' => 'M',
]);

$queue = $queueProperty->getValue($service);

if (!isset($queue[$parentId])) {
    throw new RuntimeException('Expected stockable queue to be initialised for the parent product.');
}

$combinations = array_values($queue[$parentId]);

assertSameValue(3, count($combinations), 'Queue entries with matching attributes should not overwrite each other.');

$tokens = array_map(static function (array $combination): string {
    return (string) $combination['token'];
}, $combinations);

assertSameValue(3, count(array_unique($tokens)), 'Each queued combination should keep a unique token.');

$childIds = array_map(static function (array $combination): int {
    return (int) $combination['childId'];
}, $combinations);

sort($childIds);
assertSameValue([501, 502, 503], $childIds, 'All child product identifiers should remain addressable.');

$variantIds = array_map(static function (array $combination): string {
    return (string) $combination['variantId'];
}, $combinations);

sort($variantIds);
assertSameValue(['', 'PF-AAA', 'PF-BBB'], $variantIds, 'Printful variant identifiers should remain associated with combinations.');

$prepareMethod = $syncServiceReflection->getMethod('prepareStockableDisplayLabels');
$prepareMethod->setAccessible(true);

$preparedCombinations = $prepareMethod->invoke($service, $combinations);

assertSameValue(3, count($preparedCombinations), 'Preparation should not drop combinations.');

$displayLabels = array_map(static function (array $combination): string {
    return (string) $combination['displayLabel'];
}, $preparedCombinations);

assertSameValue(3, count(array_unique($displayLabels)), 'Display labels must be unique for duplicate attribute sets.');

$descriptors = array_map(static function (array $combination): string {
    $label = (string) $combination['displayLabel'];
    $parts = explode(' – ', $label);

    return (string) array_pop($parts);
}, $preparedCombinations);

sort($descriptors);
assertSameValue(['503', 'Variant A', 'Variant B'], $descriptors, 'Duplicate descriptors should surface friendly identifiers.');

echo "All rememberStockableCombination tests passed\n";
