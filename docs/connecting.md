# Connecting

```php
use Phuze\PhpCosmos\CosmosDb;

# Cosmos URI shown in the Azure portal.
$conn = new CosmosDb('https://myaccount.documents.azure.com', 'your-key');

# selectDB() creates the database if it doesn't exist.
$database = $conn->selectDB('databaseName');

# selectCollection() creates the collection if it doesn't exist, using the
# second parameter as its partition key path. Always pass one: without it,
# Cosmos DB creates a legacy collection limited to 20 GB, and databases with
# shared throughput refuse to create it.
$collection = $database->selectCollection('Users', '/country');
```

A `CosmosDb` object creates one Guzzle client and reuses its connections for every request, so create it once and share it (for example, for the life of a worker process). `CosmosDbDatabase` and `CosmosDbCollection` objects share the connection of the `CosmosDb` they came from.

## Timeouts and Client Options

Requests time out after 60 seconds, or after 5 seconds when connecting. To change either, or to set any other [Guzzle request option](https://docs.guzzlephp.org/en/stable/request-options.html), use `setHttpClientOptions()`:

```php
$conn->setHttpClientOptions([
    'timeout'         => 120, # seconds to wait for a response
    'connect_timeout' => 10,  # seconds to wait for a connection
]);
```
