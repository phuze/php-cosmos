<?php

$db = mockDb([jsonResponse([]), jsonResponse([]), jsonResponse([])], $history);
$db->createDocument('db1', 'col1', '{}', 'a"b\\c');
$db->createDocument('db1', 'col1', '{}', 2024);
$db->createDocument('db1', 'col1', '{}', "Montr\xC3\xA9al");

check('quotes and backslashes are escaped', requestHeader($history[0], 'x-ms-documentdb-partitionkey') === '["a\"b\\\\c"]', requestHeader($history[0], 'x-ms-documentdb-partitionkey'));
check('numbers are sent as numbers', requestHeader($history[1], 'x-ms-documentdb-partitionkey') === '[2024]');
check('accented characters are sent as JSON escapes', requestHeader($history[2], 'x-ms-documentdb-partitionkey') === '["Montr' . chr(92) . 'u00e9al"]', requestHeader($history[2], 'x-ms-documentdb-partitionkey'));
