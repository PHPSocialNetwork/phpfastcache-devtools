<?php

/**
 *
 * This file is part of Phpfastcache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt and LICENCE files.
 *
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 * @author Contributors  https://github.com/PHPSocialNetwork/phpfastcache/graphs/contributors
 */
declare(strict_types=1);

namespace Phpfastcache\Tests\Helper;

use League\CLImate\CLImate;
use Phpfastcache\Api;
use Phpfastcache\Config\ConfigurationOptionInterface;
use Phpfastcache\Core\Item\ExtendedCacheItemInterface;
use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use Phpfastcache\Event\Event;
use Phpfastcache\Event\EventManagerInterface;
use Phpfastcache\Event\EventReferenceParameter;
use Phpfastcache\Exceptions\PhpfastcacheDriverCheckException;
use Phpfastcache\Exceptions\PhpfastcacheDriverConnectException;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use Phpfastcache\Exceptions\PhpfastcacheIOException;
use Phpfastcache\Exceptions\PhpfastcacheLogicException;
use Phpfastcache\Proxy\PhpfastcacheAbstractProxyInterface;
use Phpfastcache\Util\SapiDetector;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use Throwable;

/**
 * Class TestHelper
 * @package phpFastCache\Helper
 */
class TestHelper
{
    protected int $numOfFailedTests = 0;

    protected int $numOfPassedTests = 0;

    protected int $numOfSkippedTests = 0;

    protected string $testName;

    protected float $timestamp;

    protected CLImate $climate;

    /**
     * TestHelper constructor.
     *
     * @param string $testName
     * @throws PhpfastcacheIOException
     * @throws PhpfastcacheLogicException
     */
    public function __construct(string $testName)
    {
        $this->timestamp = microtime(true);
        $this->testName = $testName;
        $this->climate = new CLImate();
        $this->climate->forceAnsiOn();

        /**
         * Catch all uncaught exception
         * to our own exception handler
         */
        set_exception_handler([$this, 'exceptionHandler']);
        $this->setErrorHandler();

        $this->printHeaders();
    }

    public function mutePhpNotices(): void
    {
        $errorLevels = E_ALL & ~E_NOTICE & ~E_USER_NOTICE;
        $this->setErrorHandler($errorLevels);
        error_reporting($errorLevels);
    }

    public function unmutePhpNotices(): void
    {
        $errorLevels = E_ALL;
        $this->setErrorHandler($errorLevels);
        error_reporting($errorLevels);
    }

    /**
     * @return bool
     */
    public function isHHVM(): bool
    {
        return defined('HHVM_VERSION');
    }

    /**
     * @throws PhpfastcacheIOException
     * @throws PhpfastcacheLogicException
     */
    public function printHeaders(): void
    {
        if (SapiDetector::isWebScript() && !headers_sent()) {
            header('Content-Type: text/plain, true');
        }

        $loadedExtensions = get_loaded_extensions();
        natcasesort($loadedExtensions);
        $this->printText("[<blue>Begin Test:</blue> <magenta>$this->testName</magenta>]");
        $this->printText('[<blue>PHPFASTCACHE:</blue> CORE <yellow>v' . Api::getPhpfastcacheVersion() . Api::getPhpfastcacheGitHeadHash() . '</yellow> | API <yellow>v' . Api::getVersion() . '</yellow>]');
        $this->printText('[<blue>PHP</blue> <yellow>v' . PHP_VERSION . '</yellow> with: <green>' . implode(', ', $loadedExtensions) . '</green>]');
        $this->printText('---');
    }

    /**
     * @param array|string $string
     * @param bool $strtoupper
     * @param string $prefix
     * @return $this
     */
    public function printText(array|string $string, bool $strtoupper = false, string $prefix = ''): self
    {
        if (\is_array($string)) {
            $string = implode("\n", $string);
        }
        if ($prefix) {
            $string = "[$prefix] $string";
        }
        if (!$strtoupper) {
            $this->climate->out($string);
        } else {
            $this->climate->out(strtoupper($string));
        }

        return $this;
    }

    /**
     * @param string $string
     * @return $this
     */
    public function printNoteText(string $string): self
    {
        $this->printText($string, false, '<blue>NOTE</blue>');

        return $this;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function printNewLine(int $count = 1): self
    {
        $this->climate->out(str_repeat(PHP_EOL, $count - 1));
        return $this;
    }


    /**
     * @param string $string
     * @return $this
     */
    public function printDebugText(string $string): self
    {
        $this->printText($string, false, "\e[35mDEBUG\e[0m");

        return $this;
    }

    /**
     * @param string printFailText
     * @return $this
     */
    public function printInfoText(string $string): self
    {
        $this->printText($string, false, "\e[34mINFO\e[0m");

        return $this;
    }

    /**
     * @param string $file
     * @param string $ext
     */
    public function runSubProcess(string $file, string $ext = '.php'): void
    {
        $filePath = getcwd() . DIRECTORY_SEPARATOR . 'subprocess' . DIRECTORY_SEPARATOR . $file . '.subprocess' . $ext;
        $binary = $this->isHHVM() ? 'hhvm' : 'php';
        $this->printDebugText(\sprintf('Running %s subprocess on "%s"', \strtoupper($binary), $filePath));
        $this->runAsyncProcess("$binary $filePath");
    }

    /**
     * @param string $string
     * @param bool $failsTest
     * @return $this
     */
    public function assertFail(string $string, bool $failsTest = true): self
    {
        $this->printText($string, false, '<red>FAIL</red>');
        if ($failsTest) {
            $this->numOfFailedTests++;
        }

        return $this;
    }

    /**
     * @param string $string
     * @return $this
     */
    public function assertPass(string $string): self
    {
        $this->printText($string, false, "\e[32mPASS\e[0m");
        $this->numOfPassedTests++;

        return $this;
    }

    /**
     * @param string $string
     * @return $this
     */
    public function assertSkip(string $string): self
    {
        $this->printText($string, false, '<yellow>SKIP</yellow>');
        $this->numOfSkippedTests++;

        return $this;
    }

    public function terminateTest(): void
    {
        $execTime = round(microtime(true) - $this->timestamp, 3);
        $totalCount = $this->numOfFailedTests + $this->numOfSkippedTests + $this->numOfPassedTests;

        $this->printText(
            \sprintf(
                '<blue>Test results:</blue><%1$s> %2$s %3$s failed</%1$s>, <%4$s>%5$s %6$s skipped</%4$s> and <%7$s>%8$s %9$s passed</%7$s> out of a total of %10$s %11$s.',
                $this->numOfFailedTests ? 'red' : 'green',
                $this->numOfFailedTests,
                ngettext('assertion', 'assertions', $this->numOfFailedTests),
                $this->numOfSkippedTests ? 'yellow' : 'green',
                $this->numOfSkippedTests,
                ngettext('assertion', 'assertions', $this->numOfSkippedTests),
                !$this->numOfPassedTests && $totalCount ? 'red' : 'green',
                $this->numOfPassedTests,
                ngettext('assertion', 'assertions', $this->numOfPassedTests),
                "<cyan>$totalCount</cyan>",
                ngettext('assertion', 'assertions', $totalCount),
            )
        );
        $this->printText('<blue>Test duration: </blue><yellow>' . $execTime . 's</yellow>');
        $this->printText('<blue>Test memory: </blue><yellow>' . $this->getReadableSize(memory_get_peak_usage()) . '</yellow>');

        if ($this->numOfFailedTests) {
            exit(1);
        }

        if ($this->numOfSkippedTests) {
            exit($this->numOfPassedTests ? 0 : 2);
        }

        exit(0);
    }

    /**
     * @param string $cmd
     */
    public function runAsyncProcess(string $cmd): void
    {
        if (str_starts_with(php_uname(), 'Windows')) {
            pclose(popen('start /B ' . $cmd, 'r'));
        } else {
            exec($cmd . ' > /dev/null &');
        }
    }

    /**
     * @param $obj
     * @param $prop
     * @return mixed
     * @throws ReflectionException
     */
    public function accessInaccessibleMember($obj, $prop): mixed
    {
        $reflection = new ReflectionClass($obj);
        $property = $reflection->getProperty($prop);
        $property->setAccessible(true);
        return $property->getValue($obj);
    }

    /**
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     */
    public function errorHandler(int $errno, string $errstr, string $errfile, int $errline): void
    {
        // Silenced errors
        if (!(error_reporting() & $errno)){
            return;
        }

        $errorType = '';
        switch ($errno) {
            case E_PARSE:
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                $errorType = '[FATAL ERROR]';
                break;
            case E_WARNING:
            case E_USER_WARNING:
            case E_COMPILE_WARNING:
            case E_RECOVERABLE_ERROR:
                $errorType = '[WARNING]';
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
                $errorType = '[NOTICE]';
                break;
            case E_STRICT:
                $errorType = '[STRICT]';
                break;
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                $errorType = '[DEPRECATED]';
                break;
            default:
                break;
        }

        if ($errorType === '[FATAL ERROR]') {
            $this->assertFail(
                \sprintf(
                    "<red>A critical error has been caught:</red> <light_red>\"%s\" in %s line %d</light_red>",
                    "$errorType $errstr",
                    $errfile,
                    $errline
                )
            );
        } else {
            $this->printDebugText(
                \sprintf(
                    "<yellow>A non-critical error has been caught:</yellow> <light_cyan>\"%s\" in %s line %d</light_cyan>",
                    "$errorType $errstr",
                    $errfile,
                    $errline
                )
            );
        }
    }

    /**
     * @param EventManagerInterface $eventManager
     */
    public function debugEvents(EventManagerInterface $eventManager): void
    {
        $eventManager->onEveryEvents(
            function (string $eventName) {
                $this->printDebugText("Triggered event '$eventName'");
            },
            'debugCallback'
        );
    }

    /**
     * @param ExtendedCacheItemPoolInterface|PhpfastcacheAbstractProxyInterface $pool
     * @param bool $poolClear
     * @throws PhpfastcacheInvalidArgumentException
     * @throws InvalidArgumentException
     * @throws \Exception
     */
    public function runCRUDTests(ExtendedCacheItemPoolInterface|PhpfastcacheAbstractProxyInterface $pool, bool $poolClear = true): void
    {
        $this->printInfoText('Running CRUD tests on the following backend: ' . get_class($pool));

        if ($poolClear) {
            $this->printDebugText('Clearing backend before running test...');
            $pool->clear();
        }

        $cacheKey = 'cache_key_' . bin2hex(random_bytes(8) . '_' . random_int(100, 999));
        $cacheKey2 = 'cache_key_' . bin2hex(random_bytes(8) . '_' . random_int(100, 999));
        $cacheValue = 'cache_data_' . random_int(1000, 999999);
        $cacheTag = 'cache_tag_' . bin2hex(random_bytes(8) . '_' . random_int(100, 999));
        $cacheTag2 = 'cache_tag_' . bin2hex(random_bytes(8) . '_' . random_int(100, 999));
        $cacheItem = $pool->getItem($cacheKey);
        $this->printInfoText('Using cache key: ' . $cacheKey);

        /**
         * Default TTL - 1sec is for dealing with potential script execution delay
         * @see https://github.com/PHPSocialNetwork/phpfastcache/issues/855
         */
        if($cacheItem->getTtl() < $pool->getConfig()->getDefaultTtl() - 1) {
            $this->assertFail(\sprintf(
                'The expected TTL of the cache item was ~%ds, got %ds',
                $pool->getConfig()->getDefaultTtl(),
                $cacheItem->getTtl()
            ));
        }

        $cacheItem->set($cacheValue)
            ->addTags([$cacheTag, $cacheTag2]);

        if ($pool->save($cacheItem)) {
            $this->assertPass('The pool successfully saved an item.');
        } else {
            $this->assertFail('The pool failed to save an item.');
            return;
        }
        unset($cacheItem);
        $pool->detachAllItems();

        /**
         * Tag strategy ALL success and fail
         */

        $this->printInfoText('Re-fetching item <green>by its tags</green> <red>and an unknown tag</red> (tag strategy "<yellow>ALL</yellow>")...');
        $cacheItems = $pool->getItemsByTags([$cacheTag, $cacheTag2, 'unknown_tag'], $pool::TAG_STRATEGY_ALL);

        if (!isset($cacheItems[$cacheKey])) {
            $this->assertPass('The pool expectedly failed to retrieve the cache item.');
        } else {
            $this->assertFail('The pool unexpectedly retrieved the cache item.');
            return;
        }
        unset($cacheItems);
        $pool->detachAllItems();

        $this->printInfoText('Re-fetching item <green>by its tags</green> (tag strategy "<yellow>ALL</yellow>")...');
        $cacheItems = $pool->getItemsByTags([$cacheTag, $cacheTag2], $pool::TAG_STRATEGY_ALL);

        if (isset($cacheItems[$cacheKey])) {
            $this->assertPass('The pool successfully retrieved the cache item.');
        } else {
            $this->assertFail('The pool failed to retrieve the cache item.');
            return;
        }
        unset($cacheItems);
        $pool->detachAllItems();

        /**
         * Tag strategy ONLY success and fail
         */
        $this->printInfoText('Re-fetching item <green>by its tags</green> <red>and an unknown tag</red> (tag strategy "<yellow>ONLY</yellow>")...');
        $cacheItems = $pool->getItemsByTags([$cacheTag, $cacheTag2, 'unknown_tag'], $pool::TAG_STRATEGY_ONLY);

        if (!isset($cacheItems[$cacheKey])) {
            $this->assertPass('The pool expectedly failed to retrieve the cache item.');
        } else {
            $this->assertFail('The pool unexpectedly retrieved the cache item.');
            return;
        }
        unset($cacheItems);
        $pool->detachAllItems();

        $this->printInfoText('Re-fetching item <green>by one of its tags</green> (tag strategy "<yellow>ONLY</yellow>")...');
        $cacheItems = $pool->getItemsByTags([$cacheTag, $cacheTag2], $pool::TAG_STRATEGY_ONLY);

        if (isset($cacheItems[$cacheKey])) {
            $this->assertPass('The pool successfully retrieved the cache item.');
        } else {
            $this->assertFail('The pool failed to retrieve the cache item.');
            return;
        }
        unset($cacheItems);
        $pool->detachAllItems();

        /**
         * Tag strategy ONE success and fail
         */
        $this->printInfoText('Re-fetching item <green>by one of its tags</green> <red>and an unknown tag</red> (tag strategy "<yellow>ONE</yellow>")...');
        $cacheItems = $pool->getItemsByTags([$cacheTag, 'unknown_tag'], $pool::TAG_STRATEGY_ONE);

        if (isset($cacheItems[$cacheKey]) && $cacheItems[$cacheKey]->getKey() === $cacheKey) {
            $this->assertPass('The pool successfully retrieved the cache item.');
        } else {
            $this->assertFail('The pool failed to retrieve the cache item.');
            return;
        }
        $cacheItem = $cacheItems[$cacheKey];

        if ($cacheItem->get() === $cacheValue) {
            $this->assertPass('The pool successfully retrieved the expected value.');
        } else {
            $this->assertFail('The pool failed to retrieve the expected value.');
            return;
        }

        $this->printInfoText('Updating the cache item by appending some chars...');
        $cacheItem->append('_appended');
        $cacheValue .= '_appended';
        $pool->saveDeferred($cacheItem);
        $this->printInfoText('Deferred item is being committed...');
        if ($pool->commit()) {
            $this->assertPass('The pool successfully committed deferred cache item.');
        } else {
            $this->assertFail('The pool failed to commit deferred cache item.');
        }
        $pool->detachAllItems();
        unset($cacheItem);

        $cacheItem = $pool->getItem($cacheKey);
        if ($cacheItem->get() === $cacheValue) {
            $this->assertPass('The pool successfully retrieved the expected new value.');
        } else {
            $this->assertFail('The pool failed to retrieve the expected new value.');
            return;
        }

        if ($poolClear) {
            if ($pool->deleteItem($cacheKey) && !$pool->getItem($cacheKey)->isHit()) {
                $this->assertPass('The pool successfully deleted the cache item.');
            } else {
                $this->assertFail('The pool failed to delete the cache item.');
            }

            if ($pool->clear()) {
                $this->assertPass('The pool successfully cleared.');
            } else {
                $this->assertFail('The cluster failed to clear.');
            }
            $pool->detachAllItems();
            unset($cacheItem);

            $cacheItem = $pool->getItem($cacheKey);
            if (!$cacheItem->isHit()) {
                $this->assertPass('The cache item does no longer exists in pool.');
            } else {
                $this->assertFail('The cache item still exists in pool.');
                return;
            }
            unset($cacheItem);

            $this->printInfoText('Testing deleting multiple keys at once.');
            $cacheItems = $pool->getItems([$cacheKey, $cacheKey2]);
            foreach ($cacheItems as $cacheItem) {
                $cacheItem->set(str_shuffle($cacheValue));
                $pool->save($cacheItem);
            }
            $pool->deleteItems(array_keys($cacheItems));

            $cacheHits = array_filter(array_map(fn(ExtendedCacheItemInterface $item) => $item->isHit(), $pool->getItems([$cacheKey, $cacheKey2])));
            if(count($cacheHits) === 0) {
                $this->assertPass('The cache items does no longer exists in pool.');
            } else {
                $this->assertFail(sprintf(
                    'The cache items %s still exists in pool.', implode(', ', array_map(fn(ExtendedCacheItemInterface $item) => $item->getKey(), $cacheHits))
                ));
            }
        }

        $this->printInfoText(
            \sprintf(
                'I/O stats: %d HIT(S), %s MISS, %d WRITE(S)',
                $pool->getIO()->getReadHit(),
                $pool->getIO()->getReadMiss(),
                $pool->getIO()->getWriteHit()
            )
        );
        $stats = $pool->getStats();
        $this->printInfoText('<yellow>Driver info</yellow>: <magenta>' . $stats->getInfo() . '</magenta>');
        $poolSize = $stats->getSize();

        if($poolSize){
            $this->printInfoText('<yellow>Driver size</yellow> (approximative): <magenta>' . round($stats->getSize() / (1024 ** 2), 3) . ' Mo</magenta>');
        }
        $this->printNewLine();
    }

    /**
     * @param ExtendedCacheItemPoolInterface|PhpfastcacheAbstractProxyInterface $poolCache
     * @return void
     * @throws PhpfastcacheInvalidArgumentException
     * @throws \Phpfastcache\Exceptions\PhpfastcacheUnsupportedMethodException
     */
    public function runGetAllItemsTests(ExtendedCacheItemPoolInterface|PhpfastcacheAbstractProxyInterface $poolCache): void
    {
        $poolCache->getEventManager()->on([Event::CACHE_GET_ALL_ITEMS], function(ExtendedCacheItemPoolInterface $driver, EventReferenceParameter $referenceParameter) use (&$eventFlag){
            $callback = $referenceParameter->getParameterValue();
            $referenceParameter->setParameterValue(function(string $pattern) use ($callback, &$eventFlag) {
                $eventFlag = true;
                $this->printInfoText('The custom event Event::CACHE_GET_ALL_ITEMS has been called.');
                return $callback($pattern);
            });
        });

        $driverName = $poolCache->getDriverName();
        $this->printNoteText(
            sprintf(
                "<blue>Testing</blue> <red>%s</red> <blue>against getAllItems() method</blue>",
                strtoupper($driverName),
            )
        );
        $eventFlag = false;

        $poolCache->clear();
        $item1 = $poolCache->getItem('cache-test1');
        $item2 = $poolCache->getItem('cache-test2');
        $item3 = $poolCache->getItem('cache-test3');

        $item1->set('test1')->expiresAfter(3600);
        $item2->set('test2')->expiresAfter(3600);
        $item3->set('test3')->expiresAfter(3600);

        $poolCache->saveMultiple($item1, $item2, $item3);
        $poolCache->detachAllItems();
        unset($item1, $item2, $item3);


        $items = $poolCache->getAllItems();
        $itemCount = count($items);
        if ($itemCount === 3) {
            $this->assertPass('getAllItems() returned 3 cache items as expected.');
        } else {
            $this->assertFail(sprintf('getAllItems() unexpectedly returned %d cache items.', $itemCount));
        }

        foreach ($items as $key => $item) {
            if ($item->isHit()) {
                $this->assertPass(sprintf('Item #%s is hit.', $item->getKey()));
            } else {
                $this->assertFail(sprintf('Item #%s is not hit.', $item->getKey()));
            }

            if ($key === $item->getKey()) {
                $this->assertPass(sprintf('Cache item #%s object is identified by its cache key.', $item->getKey()));
            } else {
                $this->assertFail(sprintf('Cache item #%s object is identified by "%s".', $item->getKey(), $key));
            }
        }

        $this->printNoteText("<blue>Testing getAllItems() method</blue> <yellow>(with pattern)</yellow>");

        try {
            $items = $poolCache->getAllItems('*test1*');
            if (count($items) === 1) {
                $this->assertPass('Found 1 item using $pattern argument');
            } else {
                $this->assertFail(sprintf('Found %d items using $pattern argument', count($items)));
            }

        } catch (PhpfastcacheInvalidArgumentException) {
            $this->assertSkip("Pattern argument unsupported by $driverName driver");
        }

        $this->printNewLine();
    }

    /**
     * @param Throwable $exception
     */
    public function exceptionHandler(Throwable $exception): void
    {
        if ($exception instanceof PhpfastcacheDriverCheckException) {
            $this->assertSkip('A driver could not be initialized due to missing requirement: ' . $exception->getMessage());
        } elseif ($exception instanceof PhpfastcacheDriverConnectException) {
            $this->assertSkip('A driver could not be initialized due to network/authentication issue: ' . $exception->getMessage());
        } else {
            $filename = realpath($exception->getFile()) ?: $exception->getFile();
            $relativeFilename = '~' . str_replace($this->getProjectDir(), '', $filename);
            $this->assertFail(
                \sprintf(
                    '<red>Uncaught exception</red> <light_red>"\\%s"</light_red> <red>in</red> <light_red>"%s"</light_red> <red>line</red> <light_red>%d</light_red> <red>with message</red>: <light_red>"%s"</light_red>',
                    $exception::class,
                    str_replace('\\', '/', $relativeFilename),
                    $exception->getLine(),
                    $exception->getMessage() ?: '[No message provided]'
                )
            );
        }
        $this->terminateTest();
    }

    public function getProjectDir(): string {

        return dirname(__DIR__, 3);
    }

    public function getRandomKey(string $prefix = 'test_', int $minBlockLength = 3): string
    {
        return $prefix . \implode(
                '_',
                \array_filter(
                    \array_map(
                        static function ($str) use ($minBlockLength) {
                            return \strlen($str) < $minBlockLength ? null : $str;
                        },
                        \str_split(\bin2hex(\random_bytes(\random_int(6, 16))), \random_int($minBlockLength, $minBlockLength + 5))
                    )
                )
            );
    }

    public function preConfigure(ConfigurationOptionInterface $configurationOption): ConfigurationOptionInterface
    {
        $configurationOption->setItemDetailedDate(true)
            ->setUseStaticItemCaching(false);

        return $configurationOption;
    }

    protected function setErrorHandler(int $errorLevels = E_ALL): void
    {
        set_error_handler([$this, 'errorHandler'], $errorLevels);
    }

    protected function getReadableSize(int $bytes, int $decimals = 1) {
        $sz = 'BKMGTP';
        $factor = floor((strlen((string) $bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ($sz[$factor] ?? '') . 'o';
    }
}
