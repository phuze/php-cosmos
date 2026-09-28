# Rate Limiting (429)

When a request uses more request units than your database has provisioned, Cosmos DB rejects it with a 429 and says how long to wait before retrying in the `x-ms-retry-after-ms` response header.

By default, a 429 is thrown to your application as a `GuzzleHttp\Exception\ClientException`, so you decide what to do:

```php
use Phuze\PhpCosmos\QueryBuilder;
use GuzzleHttp\Exception\ClientException;

try {
    $res = QueryBuilder::instance()
        ->setCollection($collection)
        ->findAll(true)
        ->toArray();
}
catch (ClientException $e) {
    if ($e->getResponse()->getStatusCode() === 429) {
        $waitMs = (int)$e->getResponse()->getHeaderLine('x-ms-retry-after-ms');
        # Back off, queue the work for later, etc.
    }
}
```

## Automatic Retries

Alternatively, have the library retry for you, waiting as long as Cosmos DB asks each time:

```php
# Up to 9 retries, and up to 30 seconds of waiting in total per request.
# These are the same defaults as Microsoft's own SDKs.
$conn->setRetryOptions();

# Or set your own limits: up to 3 retries, and up to 10 seconds of waiting.
$conn->setRetryOptions(3, 10000);

# Turn retries back off.
$conn->setRetryOptions(0);
```

Some operations make several requests: `findAll()` reads a large result page by page, a cross-partition query can make one query per partition key range, and `deleteAll()` makes one request per document. With retries off, a 429 on any one of those requests stops the whole operation partway through.
