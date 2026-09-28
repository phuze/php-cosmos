<?php

use GuzzleHttp\Exception\ClientException;

$db = mockDb([throttledResponse(10), jsonResponse([])], $history);
$e = catchException(function () use ($db) { $db->getInfo(); });
check('a 429 is thrown to the caller by default', $e instanceof ClientException && $e->getResponse()->getStatusCode() === 429 && count($history) === 1);
check('the caller can read x-ms-retry-after-ms', $e instanceof ClientException && $e->getResponse()->getHeaderLine('x-ms-retry-after-ms') === '10');

$logger = new MemoryLogger();
$db = mockDb([throttledResponse(15), throttledResponse(15), jsonResponse(['ok' => 1])], $history);
$db->setLogger($logger);
$db->setRetryOptions();
$start = microtime(true);
$result = $db->getInfo();
$elapsedMs = (microtime(true) - $start) * 1000;
check('with retries on, a 429 is retried until it succeeds', $result === '{"ok":1}' && count($history) === 3);
check('each retry waits x-ms-retry-after-ms', $elapsedMs >= 28, round($elapsedMs) . 'ms');
$warnings = logRecords($logger, 'warning');
check('each retry is logged as a warning', count($warnings) === 2 && $warnings[0][2]['retry_after_ms'] === 15 && $warnings[1][2]['attempt'] === 2);

$db = mockDb([throttledResponse(1), throttledResponse(1), jsonResponse([])], $history);
$db->setRetryOptions(1, 1000);
$e = catchException(function () use ($db) { $db->getInfo(); });
check('retries stop at maxRetries', $e instanceof ClientException && count($history) === 2);

$db = mockDb([throttledResponse(100), jsonResponse([])], $history);
$db->setRetryOptions(9, 50);
$e = catchException(function () use ($db) { $db->getInfo(); });
check('retries stop when the wait would pass maxWaitMs', $e instanceof ClientException && count($history) === 1);

$db = mockDb([throttledResponse(1), jsonResponse([])], $history);
$db->setRetryOptions();
$db->setRetryOptions(0);
$e = catchException(function () use ($db) { $db->getInfo(); });
check('setRetryOptions(0) turns retries back off', $e instanceof ClientException && count($history) === 1);

$db = mockDb([throttledResponse(1), jsonResponse(['ok' => 1])], $history, ['http_errors' => false]);
$db->setRetryOptions();
check('a 429 is retried when http_errors is off', $db->getInfo() === '{"ok":1}' && count($history) === 2);

$db = mockDb([jsonResponse(['code' => 'NotFound', 'message' => 'missing'], 404), jsonResponse([])], $history);
$db->setRetryOptions();
$e = catchException(function () use ($db) { $db->getInfo(); });
check('other errors are not retried', $e instanceof ClientException && count($history) === 1);
