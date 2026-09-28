<?php

use Phuze\PhpCosmos\CosmosDbCollection;
use Phuze\PhpCosmos\QueryBuilder;

$db = mockDb([jsonResponse(['_rid' => 'new1'], 201)], $history);
$rid = QueryBuilder::instance()
    ->setCollection(new CosmosDbCollection($db, 'db1', 'col1'))
    ->save(['id' => '1', 'name' => 'x']);
check('save() works with no partition key', $rid === 'new1' && requestHeader($history[0], 'x-ms-documentdb-partitionkey') === '');

$db = mockDb([jsonResponse(['_rid' => 'new2'], 201)], $history);
QueryBuilder::instance()
    ->setCollection(new CosmosDbCollection($db, 'db1', 'col1'))
    ->setPartitionKey('/billing/country')
    ->save(['id' => '2', 'billing' => ['country' => 'Portugal']]);
check('save() finds a nested partition key in an array', requestHeader($history[0], 'x-ms-documentdb-partitionkey') === '["Portugal"]');

$db = mockDb([jsonResponse(['_rid' => 'r9'])], $history);
QueryBuilder::instance()
    ->setCollection(new CosmosDbCollection($db, 'db1', 'col1'))
    ->setPartitionKey('/vendorName')
    ->save(['_rid' => 'r9', 'id' => '9', 'vendorName' => 'Acme']);
check('save() with a _rid replaces the document', $history[0]['request']->getMethod() === 'PUT' && requestHeader($history[0], 'x-ms-documentdb-partitionkey') === '["Acme"]');

$db = mockDb([jsonResponse(['_rid' => 'r0'], 201)], $history);
QueryBuilder::instance()
    ->setCollection(new CosmosDbCollection($db, 'db1', 'col1'))
    ->setPartitionKey('/code')
    ->setPartitionValue('0')
    ->save(['id' => '0', 'code' => '0']);
check('setPartitionValue("0") is not ignored', requestHeader($history[0], 'x-ms-documentdb-partitionkey') === '["0"]');
