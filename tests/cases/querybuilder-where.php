<?php

use Phuze\PhpCosmos\CosmosDbCollection;
use Phuze\PhpCosmos\QueryBuilder;

$db = mockDb([docsResponse([]), docsResponse([]), docsResponse([])], $history);
$collection = new CosmosDbCollection($db, 'db1', 'col1');

QueryBuilder::instance()->setCollection($collection)->whereContains('c.name', "O'Brien")->findAll();
QueryBuilder::instance()->setCollection($collection)->whereStartsWith('c.a', 'x\\')->whereEndsWith('c.b', '"y')->findAll();
QueryBuilder::instance()->setCollection($collection)->whereIn('c.id', [1, "it's"])->whereNotIn('c.s', ['a'])->findAll();

$contains = requestBody($history[0])['query'];
$startsEnds = requestBody($history[1])['query'];
$in = requestBody($history[2])['query'];

check('whereContains() closes its parenthesis', strpos($contains, 'where CONTAINS(c.name, "O\'Brien")') !== false, $contains);
check('whereStartsWith() and whereEndsWith() escape backslashes and quotes', strpos($startsEnds, 'STARTSWITH(c.a, "x\\\\")') !== false && strpos($startsEnds, 'ENDSWITH(c.b, "\\"y")') !== false, $startsEnds);
check('whereIn() and whereNotIn() quote each value as a string', strpos($in, 'c.id IN("1", "it\'s")') !== false && strpos($in, 'c.s NOT IN("a")') !== false, $in);
