<?php

$db = mockDb([
    jsonResponse(['Databases' => []]),
    jsonResponse(['_rid' => 'dbrid']),
    jsonResponse(['DocumentCollections' => []]),
    jsonResponse(['_rid' => 'colrid']),
], $history);

$database = $db->selectDB('my "db"');
check('selectDB() creates a database with a JSON-encoded name', requestBody($history[1]) === ['id' => 'my "db"']);

$collection = $database->selectCollection('things', 'myPartitionKey');
$paths = requestBody($history[3])['partitionKey']['paths'];
check('selectCollection() adds the leading slash to the partition key path', $paths === ['/myPartitionKey'], json_encode($paths));
check('a collection exposes its _rids, to be cached', $collection->getDbRid() === 'dbrid' && $collection->getCollRid() === 'colrid');
