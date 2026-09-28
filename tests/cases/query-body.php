<?php

use Phuze\PhpCosmos\CosmosDbCollection;

$db = mockDb([docsResponse([])], $history);
(new CosmosDbCollection($db, 'db1', 'col1'))->query('SELECT * FROM c WHERE c.a = @s', [
    '@s'   => 'back\\slash "quoted" ' . "\xC3\xA9",
    '@t'   => true,
    '@f'   => false,
    '@n'   => null,
    '@i'   => 5,
    '@fl'  => 1.5,
    '@arr' => ['x', 2],
]);
$body = requestBody($history[0]);
$params = [];
foreach ($body['parameters'] as $p) {
    $params[$p['name']] = $p['value'];
}
check('the query text is sent as written', $body['query'] === 'SELECT * FROM c WHERE c.a = @s');
check('backslashes, quotes and accents in a parameter survive', $params['@s'] === 'back\\slash "quoted" ' . "\xC3\xA9");
check('true, false and null keep their type', $params['@t'] === true && $params['@f'] === false && array_key_exists('@n', $params) && $params['@n'] === null);
check('numbers and arrays keep their type', $params['@i'] === 5 && $params['@fl'] === 1.5 && $params['@arr'] === ['x', 2]);
check('query headers are set', requestHeader($history[0], 'Content-Type') === 'application/query+json' && requestHeader($history[0], 'x-ms-documentdb-isquery') === 'True');

$db = mockDb([docsResponse([])], $history);
(new CosmosDbCollection($db, 'db1', 'col1'))->query('SELECT * FROM c');
check('no parameters is sent as an empty array', strpos((string)$history[0]['request']->getBody(), '"parameters":[]') !== false);

$db = mockDb([], $history);
$e = catchException(function () use ($db) {
    (new CosmosDbCollection($db, 'db1', 'col1'))->query('SELECT * FROM c WHERE c.a = @a', ['@a' => "\xB1\x31"]);
});
check('a parameter that is not valid UTF-8 throws InvalidArgumentException', $e instanceof InvalidArgumentException && count($history) === 0);
