<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Phuze\PhpCosmos\CosmosDb;

$passes = 0;
$failures = 0;

# a psr-3 logger that keeps its records. psr/log 3.x (php 8) adds types to
# log(), which php 7 can't parse, so the class is declared to suit the version.
$logTyped = (new ReflectionMethod('Psr\Log\LoggerInterface', 'log'))->hasReturnType();
eval('class MemoryLogger extends \Psr\Log\AbstractLogger {
    public $records = [];
    public function log($level, ' . ($logTyped ? 'string|\Stringable ' : '') . '$message, array $context = [])' . ($logTyped ? ': void' : '') . ' {
        $this->records[] = [$level, (string)$message, $context];
    }
}');

/**
 * record one test result
 *
 * @param string $name
 * @param bool $passed
 * @param mixed $detail shown when the test fails, ie: the value that was wrong
 */
function check(string $name, bool $passed, $detail = '')
{
    global $passes, $failures;

    if ($passed) {
        $passes++;
        echo "PASS  {$name}" . PHP_EOL;
    }
    else {
        $failures++;
        $detail = is_string($detail) ? $detail : json_encode($detail);
        echo "FAIL  {$name}" . ($detail !== '' ? "  -- {$detail}" : '') . PHP_EOL;
    }
}

/**
 * a CosmosDb whose requests are answered in turn from $queue, which holds
 * responses or exceptions. every request sent is appended to $history.
 */
function mockDb(array $queue, &$history, array $httpClientOptions = [])
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));

    $db = new CosmosDb('https://example.documents.azure.com:443/', base64_encode('key'));
    $db->setHttpClientOptions(array_merge(['handler' => $stack], $httpClientOptions));

    return $db;
}

function jsonResponse(array $data, int $status = 200, array $headers = [])
{
    return new Response($status, $headers + ['x-ms-request-charge' => '2.5', 'x-ms-activity-id' => 'activity-1'], json_encode($data));
}

/**
 * a page of query results
 */
function docsResponse(array $documents, array $headers = [])
{
    return jsonResponse(['_rid' => 'col', 'Documents' => $documents, '_count' => count($documents)], 200, $headers);
}

function throttledResponse(int $retryAfterMs)
{
    return jsonResponse(['code' => '429', 'message' => 'Request rate is large'], 429, ['x-ms-retry-after-ms' => (string)$retryAfterMs]);
}

/**
 * what cosmos returns for a cross partition query the gateway can't serve
 */
function gatewayErrorResponse()
{
    return jsonResponse(['code' => 'BadRequest', 'message' => 'The provided cross partition query can not be directly served by the gateway.'], 400);
}

function pkRangesResponse(array $ids)
{
    return jsonResponse(['_rid' => 'col', 'PartitionKeyRanges' => array_map(function ($id) { return ['id' => $id]; }, $ids)]);
}

/**
 * the decoded JSON body of a recorded request
 */
function requestBody(array $entry)
{
    return json_decode((string)$entry['request']->getBody(), true);
}

function requestHeader(array $entry, string $name)
{
    return $entry['request']->getHeaderLine($name);
}

/**
 * run $fn and return what it threw, or null
 */
function catchException(callable $fn)
{
    try {
        $fn();
    }
    catch (Exception $e) {
        return $e;
    }
    return null;
}

/**
 * read a private property, ie: to check which guzzle client is in use
 */
function privateProperty($object, string $name)
{
    $read = function () use ($name) { return $this->{$name}; };
    return $read->call($object);
}

/**
 * the log records at one level
 */
function logRecords(MemoryLogger $logger, string $level)
{
    return array_values(array_filter($logger->records, function ($record) use ($level) {
        return $record[0] === $level;
    }));
}
