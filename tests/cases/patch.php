<?php

use Phuze\PhpCosmos\CosmosDbCollection;
use Phuze\PhpCosmos\QueryBuilder;

$db = mockDb([jsonResponse(['_rid' => 'r1'])], $history);
$qb = QueryBuilder::instance()
    ->setCollection(new CosmosDbCollection($db, 'db1', 'col1'))
    ->setPartitionValue('CA')
    ->where('c.stock > 0');

$rid = $qb->patch('r1', [
    $qb->getPatchOpSet('/name', 'x'),
    $qb->getPatchOpAdd('/tags/-', 't'),
    $qb->getPatchOpReplace('/a', 1),
    $qb->getPatchOpRemove('/b'),
    $qb->getPatchOpIncrement('/stock', -2),
    $qb->getPatchOpMove('/old', '/new'),
]);
$body = requestBody($history[0]);

check('patch() sends a PATCH with the patch content type', $rid === 'r1' && $history[0]['request']->getMethod() === 'PATCH' && requestHeader($history[0], 'Content-Type') === 'application/json_patch+json');
check('patch() sends the partition value', requestHeader($history[0], 'x-ms-documentdb-partitionkey') === '["CA"]');
check('patch() makes where() the condition', $body['condition'] === 'from c where c.stock > 0');
check('the operation names are right', array_column($body['operations'], 'op') === ['set', 'add', 'replace', 'remove', 'incr', 'move'], json_encode(array_column($body['operations'], 'op')));
check('a move has from and path', $body['operations'][5] === ['op' => 'move', 'from' => '/old', 'path' => '/new']);

$e = catchException(function () use ($qb) { $qb->patch('r1', array_fill(0, 11, $qb->getPatchOpRemove('/x'))); });
check('more than 10 operations is rejected without a request', $e instanceof Exception && count($history) === 1);

check('triggers accept the patch operation', catchException(function () use ($qb) { $qb->addTrigger('patch', 'pre', 'trigger1'); }) === null);

$db = mockDb([jsonResponse(['_rid' => 'r2'])], $history);
$qb = QueryBuilder::instance()->setCollection(new CosmosDbCollection($db, 'db1', 'col1'))->setPartitionValue('CA');
$qb->patch('r2', [$qb->getPatchOpSet('/name', 'y')]);
check('patch() without where() has no condition', !array_key_exists('condition', requestBody($history[0])));
