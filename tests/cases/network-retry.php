<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;

$request = new Request('GET', 'https://example.documents.azure.com/');

$logger = new MemoryLogger();
$db = mockDb([new ConnectException('cURL error 52: Empty reply from server', $request), jsonResponse(['ok' => 1])], $history);
$db->setLogger($logger);
check('a connection error is retried once', $db->getInfo() === '{"ok":1}' && count($history) === 2);
check('the retry is logged as a warning', count(logRecords($logger, 'warning')) === 1);

$db = mockDb([new RequestException('cURL error 56: Connection reset by peer', $request), jsonResponse(['ok' => 1])], $history);
check('a network error with no response is retried once', $db->getInfo() === '{"ok":1}' && count($history) === 2);

# guzzle 8 reports network errors as NetworkException
if (class_exists('GuzzleHttp\Exception\NetworkException')) {
    $db = mockDb([new \GuzzleHttp\Exception\NetworkException('cURL error 56: Connection reset by peer', $request), jsonResponse(['ok' => 1])], $history);
    check('a guzzle 8 NetworkException is retried once', $db->getInfo() === '{"ok":1}' && count($history) === 2);
}

$db = mockDb([new ConnectException('down', $request), new ConnectException('still down', $request), jsonResponse([])], $history);
$e = catchException(function () use ($db) { $db->getInfo(); });
check('a second connection error is thrown', $e instanceof ConnectException && $e->getMessage() === 'still down' && count($history) === 2);

$db = mockDb([new ConnectException('down', $request), jsonResponse([])], $history);
$e = catchException(function () use ($db) { $db->patchDocument('db1', 'col1', 'doc1', '{"operations":[]}', 'pk'); });
check('PATCH is not retried', $e instanceof ConnectException && count($history) === 1);

$db = mockDb([new ConnectException('down', $request), jsonResponse([])], $history);
$e = catchException(function () use ($db) { $db->executeStoredProcedure('db1', 'col1', 'sp1', '[]'); });
check('stored procedures are not retried', $e instanceof ConnectException && count($history) === 1);
