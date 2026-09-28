<?php

/**
 * runs the test suite: every file in tests/cases, in name order.
 *
 * requests go to a fake azure (a guzzle MockHandler), so no cosmos db account
 * is needed. this doesn't use phpunit, so the same tests run unchanged on every
 * supported php version (7.0 to 8.x) and on guzzle 6, 7 and 8.
 *
 * usage: composer test
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/helpers.php';

error_reporting(E_ALL);

# a notice, warning or deprecation raised by the library fails the run. so does
# one a dependency raises about how the library called it (ie: guzzle warning
# about an option). php's own deprecations inside dependencies, such as guzzle 6
# on php 8.4, are ignored.
$sourceDir = str_replace('\\', '/', realpath(__DIR__ . '/../src')) . '/';
set_error_handler(function ($level, $message, $file, $line) use ($sourceDir) {
    $inSource = function ($path) use ($sourceDir) {
        return strpos(str_replace('\\', '/', $path), $sourceDir) === 0;
    };

    if ($inSource($file)) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }

    if (in_array($level, [E_USER_DEPRECATED, E_USER_NOTICE, E_USER_WARNING], true)) {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['file']) && $inSource($frame['file'])) {
                throw new ErrorException($message, 0, $level, $frame['file'], $frame['line']);
            }
        }
    }

    return true;
});

$cases = glob(__DIR__ . '/cases/*.php');
sort($cases);

foreach ($cases as $case) {
    echo PHP_EOL . '--- ' . basename($case, '.php') . PHP_EOL;
    try {
        require $case;
    }
    catch (Throwable $e) {
        check('no unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
}

$guzzle = defined('GuzzleHttp\ClientInterface::MAJOR_VERSION')
    ? GuzzleHttp\ClientInterface::MAJOR_VERSION
    : GuzzleHttp\ClientInterface::VERSION;

echo PHP_EOL . ($failures === 0 ? "ALL {$passes} PASSED" : "{$failures} FAILED, {$passes} passed")
    . " (php " . PHP_VERSION . ", guzzle {$guzzle})" . PHP_EOL;

exit($failures === 0 ? 0 : 1);
