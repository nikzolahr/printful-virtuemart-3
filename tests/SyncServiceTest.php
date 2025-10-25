<?php

declare(strict_types=1);

namespace Joomla\CMS\Log {
    class Log
    {
        public const INFO = 'info';
        public const WARNING = 'warning';
        public const ERROR = 'error';

        public static array $entries = [];

        public static function add($message, $level = null, $channel = null): void
        {
            self::$entries[] = [$message, $level, $channel];
        }
    }
}

namespace Joomla\CMS {
    class Factory
    {
        public static $db;
        public static $user;
        public static $language;

        public static function getDbo()
        {
            return self::$db;
        }

        public static function getUser()
        {
            return self::$user ?? (object) ['id' => 0];
        }

        public static function getLanguage()
        {
            return self::$language ?? new class {
                public function getTag(): string
                {
                    return 'en-GB';
                }
            };
        }
    }
}

namespace Joomla\CMS\Date {
    class Date
    {
        public function __construct($date = 'now', $tz = null)
        {
        }

        public function toSql(): string
        {
            return '2024-01-01 00:00:00';
        }
    }
}

namespace Joomla\CMS\Filesystem {
    class File
    {
        public static function getExt($path)
        {
            return pathinfo((string) $path, PATHINFO_EXTENSION);
        }
    }

    class Folder
    {
        public static function exists($path)
        {
            return true;
        }

        public static function create($path)
        {
            return true;
        }
    }
}

namespace Joomla\CMS\Http {
    class HttpFactory
    {
        public static function getHttp($options = [], $transport = 'curl')
        {
            return new class {
                public function get($url)
                {
                    return (object) ['body' => '', 'headers' => []];
                }
            };
        }
    }
}

namespace Joomla\Registry {
    class Registry
    {
        private array $data;

        public function __construct(array $data = [])
        {
            $this->data = $data;
        }

        public function get(string $key, $default = null)
        {
            return $this->data[$key] ?? $default;
        }

        public function set(string $key, $value): void
        {
            $this->data[$key] = $value;
        }
    }
}

namespace {
    class FakeLanguage
    {
        private string $tag;

        public function __construct(string $tag)
        {
            $this->tag = $tag;
        }

        public function getTag(): string
        {
            return $this->tag;
        }
    }

    class FakeQuery
    {
        public function select($columns)
        {
            return $this;
        }

        public function from($table)
        {
            return $this;
        }

        public function where($condition)
        {
            return $this;
        }

        public function order($ordering)
        {
            return $this;
        }

        public function update($table)
        {
            return $this;
        }

        public function set($assignment)
        {
            return $this;
        }

        public function delete($table)
        {
            return $this;
        }

        public function insert($table)
        {
            return $this;
        }

        public function values($values)
        {
            return $this;
        }

        public function __toString(): string
        {
            return 'fake-query';
        }
    }

    class FakeDatabase
    {
        public array $loadResults = [];
        public array $insertedObjects = [];
        public array $updatedObjects = [];
        public array $setQueries = [];
        private int $autoIncrement = 0;
        private $lastQuery;

        public function getQuery($new = true)
        {
            return new FakeQuery();
        }

        public function quoteName($value)
        {
            return (string) $value;
        }

        public function quote($value)
        {
            if (is_numeric($value)) {
                return (string) $value;
            }

            return "'" . (string) $value . "'";
        }

        public function setQuery($query, $offset = null, $limit = null)
        {
            $this->lastQuery = $query;
            $this->setQueries[] = (string) $query;
        }

        public function loadResult()
        {
            if (!empty($this->loadResults)) {
                return array_shift($this->loadResults);
            }

            return null;
        }

        public function execute(): void
        {
        }

        public function insertObject($table, $object, $key = null): void
        {
            if ($key !== null) {
                $this->autoIncrement++;
                $object->$key = $this->autoIncrement;
            }

            if (!isset($this->insertedObjects[$table])) {
                $this->insertedObjects[$table] = [];
            }

            $this->insertedObjects[$table][] = clone $object;
        }

        public function updateObject($table, $object, $key): void
        {
            if (!isset($this->updatedObjects[$table])) {
                $this->updatedObjects[$table] = [];
            }

            $this->updatedObjects[$table][] = clone $object;
        }

        public function getPrefix(): string
        {
            return 'jos_';
        }
    }

    class plgVmExtendedPrintful
    {
    }
}

namespace {
    if (!defined('_JEXEC')) {
        define('_JEXEC', 1);
    }

    require_once __DIR__ . '/../classes/SyncService.php';

    function assertSame($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message !== '' ? $message : sprintf('Failed asserting that %s is identical to %s.', var_export($actual, true), var_export($expected, true)));
        }
    }

    function assertFloatEquals(float $expected, float $actual, string $message = '', float $delta = 0.0001): void
    {
        if (abs($expected - $actual) > $delta) {
            throw new \RuntimeException($message !== '' ? $message : sprintf('Failed asserting that %.4f matches expected %.4f.', $actual, $expected));
        }
    }

    function testParentPriceEnsuredFromCombinations(): void
    {
        $db = new FakeDatabase();
        $db->loadResults = [47, 0, 0];

        \Joomla\CMS\Factory::$db = $db;
        \Joomla\CMS\Factory::$user = (object) ['id' => 99];
        \Joomla\CMS\Factory::$language = new FakeLanguage('en-GB');

        $plugin = new plgVmExtendedPrintful();
        $params = new \Joomla\Registry\Registry(['vendor_id' => 1]);
        $service = new \PlgVmExtendedPrintfulSyncService($plugin, $params);

        $reflection = new \ReflectionClass($service);
        $initialise = $reflection->getMethod('initialiseStockableParent');
        $initialise->setAccessible(true);
        $remember = $reflection->getMethod('rememberStockableCombination');
        $remember->setAccessible(true);
        $synchronise = $reflection->getMethod('synchroniseStockableCustomFields');
        $synchronise->setAccessible(true);

        $initialise->invoke($service, 1);
        $remember->invoke($service, 1, 101, 'v1', 'Variant 1', 'SKU1', 19.95, ['color' => 'Blue', 'size' => 'M']);
        $remember->invoke($service, 1, 102, 'v2', 'Variant 2', 'SKU2', 17.45, ['color' => 'Blue', 'size' => 'L']);

        $synchronise->invoke($service, 1, [5, 6], false);

        $prices = $db->insertedObjects['#__virtuemart_product_prices'] ?? [];

        if (empty($prices)) {
            throw new \RuntimeException('Expected parent price to be created.');
        }

        $inserted = end($prices);

        assertSame(1, $inserted->virtuemart_product_id, 'Parent price should target the parent product.');
        assertFloatEquals(17.45, (float) $inserted->product_price, 'Parent price should adopt the cheapest combination.');
    }

    echo "Running SyncService tests\n";

    testParentPriceEnsuredFromCombinations();

    echo "All tests passed\n";
}
