<?php

use Phuze\PhpCosmos\CosmosDbCollection;
use Phuze\PhpCosmos\QueryBuilder;

$db = mockDb([jsonResponse(['_rid' => 'r1'])], $history);
(new CosmosDbCollection($db, 'db1', 'coll1'))->upsertDocument('{"id":"1"}', 'Canada');
check('upsertDocument() posts to the docs feed', $history[0]['request']->getMethod() === 'POST' && $history[0]['request']->getUri()->getPath() === '/dbs/db1/colls/coll1/docs');
check('upsertDocument() sends the upsert header', requestHeader($history[0], 'x-ms-documentdb-is-upsert') === 'True');
check('upsertDocument() sends the partition value', requestHeader($history[0], 'x-ms-documentdb-partitionkey') === '["Canada"]');

$db = mockDb([jsonResponse(['_rid' => 'r2'])], $history);
$rid = QueryBuilder::instance()
    ->setCollection(new CosmosDbCollection($db, 'db1', 'coll1'))
    ->setPartitionKey('/billing/country')
    ->upsert(['_rid' => 'r2', 'id' => '2', 'billing' => ['country' => 'Portugal']]);
check('upsert() returns the _rid', $rid === 'r2');
check('upsert() posts, even with a _rid', $history[0]['request']->getMethod() === 'POST' && requestHeader($history[0], 'x-ms-documentdb-is-upsert') === 'True');
check('upsert() finds the partition value', requestHeader($history[0], 'x-ms-documentdb-partitionkey') === '["Portugal"]');

$db = mockDb([jsonResponse(['_rid' => 'r3'])], $history);
QueryBuilder::instance()
    ->setCollection(new CosmosDbCollection($db, 'db1', 'coll1'))
    ->addTrigger('upsert', 'pre', 'stamp')
    ->addTrigger('create', 'pre', 'onCreate')
    ->addTrigger('all', 'post', 'audit')
    ->upsert(['id' => '3']);
check('upsert() sends upsert triggers but not create triggers', requestHeader($history[0], 'x-ms-documentdb-pre-trigger-include') === 'stamp', requestHeader($history[0], 'x-ms-documentdb-pre-trigger-include'));
check('upsert() sends triggers added for all operations', requestHeader($history[0], 'x-ms-documentdb-post-trigger-include') === 'audit');
