<?php

use Phuze\PhpCosmos\CosmosDb;
use Phuze\PhpCosmos\CosmosDbCollection;

$db = mockDb([jsonResponse(['id' => 'doc1'])], $history);
$db->debug = true;
ob_start();
$result = $db->getDocument('db1', 'col1', 'doc1');
$output = ob_get_clean();
check('debug mode echoes the request', strpos($output, 'DEBUG (CosmosDb::request)') !== false);
check('debug mode does not empty the response body', $result === '{"id":"doc1"}', $result);
check('$debug is a declared property', property_exists(CosmosDb::class, 'debug'));

$logger = new MemoryLogger();
$db = mockDb([docsResponse([])], $history);
$db->setLogger($logger);
(new CosmosDbCollection($db, 'db1', 'col1'))->query('SELECT * FROM c', [], false, 'CA');

$requests = array_values(array_filter(logRecords($logger, 'debug'), function ($record) {
    return strpos($record[1], 'POST') !== false;
}));
check('each request is logged at debug level', count($requests) === 1);
$context = $requests ? $requests[0][2] : [];
check('the request log has status, duration, request charge and activity id', ($context['status'] ?? null) === 200
    && is_int($context['duration_ms'] ?? null)
    && ($context['request_charge'] ?? null) === 2.5
    && ($context['activity_id'] ?? null) === 'activity-1');

$queries = array_values(array_filter($logger->records, function ($record) {
    return $record[1] === 'Cosmos DB query';
}));
check('each query is logged with its text and partition value', count($queries) === 1
    && strpos($queries[0][2]['query'], 'SELECT * FROM c') !== false
    && $queries[0][2]['partition_value'] === 'CA');

$logged = json_encode($logger->records);
check('the authorization header is never logged', stripos($logged, 'authorization') === false && strpos($logged, 'sig=') === false);
