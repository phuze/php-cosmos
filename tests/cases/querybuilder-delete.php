<?php

use Phuze\PhpCosmos\CosmosDbCollection;
use Phuze\PhpCosmos\QueryBuilder;

# partition key, the selector it becomes, the document the query returns, the header the delete sends
$cases = [
    ['/form/type', 'c.form.type', ['_rid' => 'r1', 'type' => 'invoice'], '["invoice"]'],
    ['/vendorName', 'c.vendorName', ['_rid' => 'r2', 'vendorName' => 'Acme'], '["Acme"]'],
    ['billing.country', 'c.billing.country', ['_rid' => 'r3', 'country' => 'Portugal'], '["Portugal"]'],
    ['country', 'c.country', ['_rid' => 'r4', 'country' => 'Canada'], '["Canada"]'],
];

foreach ($cases as $case) {
    list($key, $selector, $document, $header) = $case;

    $db = mockDb([docsResponse([$document]), jsonResponse([], 204)], $history);
    $deleted = QueryBuilder::instance()
        ->setCollection(new CosmosDbCollection($db, 'db1', 'col1'))
        ->setPartitionKey($key)
        ->where("c.id = 'x'")
        ->delete();

    $query = requestBody($history[0])['query'];
    check("delete() with '{$key}' queries {$selector}", $deleted && strpos($query, "c._rid, {$selector}") !== false, $query);
    check("delete() with '{$key}' deletes in partition {$header}", $history[1]['request']->getMethod() === 'DELETE'
        && requestHeader($history[1], 'x-ms-documentdb-partitionkey') === $header
        && substr($history[1]['request']->getUri()->getPath(), -3) === '/' . $document['_rid'],
        requestHeader($history[1], 'x-ms-documentdb-partitionkey'));
}

$db = mockDb([docsResponse([['_rid' => 'r1', 'type' => 'a'], ['_rid' => 'r2', 'type' => 'b']]), jsonResponse([], 204), jsonResponse([], 204)], $history);
QueryBuilder::instance()
    ->setCollection(new CosmosDbCollection($db, 'db1', 'col1'))
    ->setPartitionKey('/form/type')
    ->deleteAll(true);
check('deleteAll() with a slash-style nested key', requestHeader($history[1], 'x-ms-documentdb-partitionkey') === '["a"]' && requestHeader($history[2], 'x-ms-documentdb-partitionkey') === '["b"]');

$db = mockDb([docsResponse([])], $history);
$deleted = QueryBuilder::instance()
    ->setCollection(new CosmosDbCollection($db, 'db1', 'col1'))
    ->setPartitionKey('country')
    ->where("c.id = 'missing'")
    ->delete();
check('delete() returns false when nothing matches', $deleted === false && count($history) === 1);
