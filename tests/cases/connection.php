<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

$db = mockDb([jsonResponse([]), jsonResponse([])], $history);
$db->getInfo();
$client = privateProperty($db, 'httpClient');
$db->getInfo();
check('one guzzle client is shared across requests', $client !== null && $client === privateProperty($db, 'httpClient'));

$uri = $history[0]['request']->getUri();
check('the path is joined to the host without a double slash', $uri->getHost() === 'example.documents.azure.com' && $uri->getPath() === '/', (string)$uri);
check('default timeout is 60s, and 5s to connect', $history[0]['options']['timeout'] === 60.0 && $history[0]['options']['connect_timeout'] === 5.0);
if (defined('CURLOPT_TCP_KEEPALIVE')) {
    check('tcp keep-alive is turned on', ($history[0]['options']['curl'][CURLOPT_TCP_KEEPALIVE] ?? null) === 1);
}

$db->setHttpClientOptions([
    'handler' => HandlerStack::create(new MockHandler([jsonResponse([])])),
    'timeout' => 7.0,
    'curl'    => [CURLOPT_VERBOSE => false],
]);
check('setHttpClientOptions() discards the current client', privateProperty($db, 'httpClient') === null);

$db->getInfo();
$config = privateProperty($db, 'httpClient')->getConfig();
check('new client options apply', $config['timeout'] === 7.0);
check('curl options are merged with the keep-alive defaults', isset($config['curl'][CURLOPT_VERBOSE]) && (!defined('CURLOPT_TCP_KEEPALIVE') || isset($config['curl'][CURLOPT_TCP_KEEPALIVE])));

check('the user agent carries the library version', requestHeader($history[0], 'User-Agent') === 'cosmos.php.sdk/' . \Phuze\PhpCosmos\CosmosDb::VERSION);
