<?php

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use Phuze\PhpCosmos\CosmosDbCollection;
use Phuze\PhpCosmos\QueryBuilder;

$db = mockDb([
    gatewayErrorResponse(),
    pkRangesResponse(['0', '1']),
    docsResponse([['id' => 'a']], ['x-ms-continuation' => 'token-1']),
    docsResponse([['id' => 'b']]),
    docsResponse([['id' => 'c']]),

    # a second query on the same CosmosDb uses the cached ranges. one of
    # them has since split, so cosmos answers 410 Gone for it.
    gatewayErrorResponse(),
    jsonResponse(['code' => 'Gone', 'message' => 'partition key range is gone'], 410),
    pkRangesResponse(['2', '3']),
    docsResponse([['id' => 'd']]),
    docsResponse([['id' => 'e']]),
], $history);

$qb = QueryBuilder::instance()->setCollection(new CosmosDbCollection($db, 'db1', 'col1'))->select('c.id')->order('c.id');

$ids = array_column($qb->findAll(true)->toArray(), 'id');
check('falls back to one query per range, following continuation tokens', $ids === ['a', 'b', 'c'], json_encode($ids));
check('page 2 of range 0 sends the continuation token', requestHeader($history[3], 'x-ms-continuation') === 'token-1' && requestHeader($history[3], 'x-ms-documentdb-partitionkeyrangeid') === '0');
check('the continuation token is not carried into range 1', requestHeader($history[4], 'x-ms-continuation') === '' && requestHeader($history[4], 'x-ms-documentdb-partitionkeyrangeid') === '1');

$ids = array_column($qb->findAll(true)->toArray(), 'id');
$pkRangeRequests = array_filter($history, function ($entry) {
    return substr($entry['request']->getUri()->getPath(), -9) === '/pkranges';
});
check('a 410 Gone refreshes the cached ranges and starts over', $ids === ['d', 'e'], json_encode($ids));
check('ranges are fetched once, and again after the 410', count($pkRangeRequests) === 2);

$db = mockDb([jsonResponse(['code' => 'BadRequest', 'message' => 'Syntax error'], 400)], $history);
$e = catchException(function () use ($db) { (new CosmosDbCollection($db, 'db1', 'col1'))->query('SELEKT', [], true); });
check('other query errors are thrown', $e instanceof ClientException && count($history) === 1);
check('the error body can still be read after it is thrown', $e instanceof ClientException && strpos((string)$e->getResponse()->getBody(), 'Syntax error') !== false);

$db = mockDb([new Response(400, [], 'not json')], $history);
$e = catchException(function () use ($db) { (new CosmosDbCollection($db, 'db1', 'col1'))->query('SELECT', [], true); });
check('an error body that is not JSON is thrown without a PHP warning', $e instanceof ClientException);
