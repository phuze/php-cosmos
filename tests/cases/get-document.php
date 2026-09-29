<?php

use Phuze\PhpCosmos\CosmosDbCollection;

$db = mockDb([jsonResponse(['_rid' => 'doc1', 'id' => '1']), jsonResponse(['_rid' => 'doc2'])], $history);
$collection = new CosmosDbCollection($db, 'db1', 'coll1');

$doc = json_decode($collection->getDocument('doc1', 5, ['x-ms-consistency-level' => 'Eventual']));
check('getDocument() reads the document by _rid', $history[0]['request']->getMethod() === 'GET' && $history[0]['request']->getUri()->getPath() === '/dbs/db1/colls/coll1/docs/doc1' && $doc->id === '1');
check('getDocument() sends the partition value', requestHeader($history[0], 'x-ms-documentdb-partitionkey') === '[5]');
check('getDocument() sends extra headers', requestHeader($history[0], 'x-ms-consistency-level') === 'Eventual');

$collection->getDocument('doc2');
check('getDocument() sends no partition value unless given one', requestHeader($history[1], 'x-ms-documentdb-partitionkey') === '');
