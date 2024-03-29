# php-cosmos

PHP wrapper for Azure Cosmos DB

## Installation

Install phuze/php-cosmos in your project:

```bash
composer require phuze/php-cosmos
```

## Changelog

### v3.0.4
- bug fixes and other minor changes

### v3.0.0
- restore support for PHP 7.x -- this library can be used with both 7.x and 8.x
- improved how nested partition keys are handled
- improved how partition values are matched
- fixed an issue which prevented document deletion when a container used a nested partition key
- fixed an issue with `partitionkeyrangeid` headers, when a cross-partition query needs to be retried with PK ranges

### v2.6.0
- code refactor. min PHP verion supported is now 8.0
- selectCollection no longer creates a colletion if not exist. use createCollection for that
- bug fixes

### v2.5.0
- support partitioned queries using new method `setPartitionValue()`
- support creating partitioned collections
- support for nested partition keys

### v2.0.0
- support for cross partition queries
- selectCollection method removed from all methods for performance improvements

### v1.4.4
- replaced pear package http_request2 by guzzle
- added method to provide guzzle configuration

### v1.3.0
- added support for parameterized queries

## Notes

- Currently uses Microsoft API version `2018-12-31`
- Based on [AzureDocumentDB-PHP](https://github.com/cocteau666/AzureDocumentDB-PHP) and [CosmosDb](https://github.com/jupitern/cosmosdb).
- Planned updates include:
    - support for new [PATCH](https://learn.microsoft.com/en-us/azure/cosmos-db/partial-document-update) api operations
    - enhanced debug and logging support

## Usage

### Connecting

```php
$conn = new \Phuze\PhpCosmos\CosmosDb('host', 'key');
$conn->setHttpClientOptions(['verify' => false]); # optional: set guzzle client options.
$db = $conn->selectDB('databaseName');
$collection = $db->selectCollection('collectionName');

# if a collection does not exist, it will be created when you
# attempt to select the collection. however, if you have created
# your database with shared throughput, then all collections require a partition key.
# selectCollection() supports a second parameter for this purpose.
$conn = new \Phuze\PhpCosmos\CosmosDb('host', 'key');
$conn->setHttpClientOptions(['verify' => false]); # optional: set guzzle client options.
$db = $conn->selectDB('dbName');
$collection = $db->selectCollection('collectionName', 'myPartitionKey');
```

### Inserting Records

```php
# consider a existing collection called "Users" with a partition key "country"

# insert a record
$rid = \Phuze\PhpCosmos\QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->save(['id' => '1', 'name' => 'John Doe', 'age' => 22, 'country' => 'Portugal']);

# insert a record against a collection with a nested partition key
$rid = \Phuze\PhpCosmos\QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('billing.country')
    ->save([
        'id' => '2',
        'name' => 'Jane Doe',
        'age' => 35,
        'billing' => ['country' => 'Portugal']
    ]);
```

### Updating Records

```php
# update a record
$rid = \Phuze\PhpCosmos\QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->save([
        '_rid'    => $rid, // existing document _rid
        'id'      => '2',
        'name'    => 'Jane Doe',
        'age'     => 36,
        'country' => 'Portugal'
    ]);
```

### Querying Records

```php
# query a document and return it as an array
$res = \Phuze\PhpCosmos\QueryBuilder::instance()
    ->setCollection($collection)
    ->select("c.id, c.name")
    ->where("c.age > @age and c.country = @country")
    ->params(['@age' => 30, '@country' => 'Portugal'])
    ->find(true)
    ->toArray();

# query a document using a known partition value,
# and return as an array. note: setting a known
# partition value will result in a more efficient
# query against your database as it will not rely
# on cross-partition querying.
$res = \Phuze\PhpCosmos\QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->setPartitionValue('Portugal')
    ->select("c.id, c.name")
    ->where("c.age > @age and c.country = @country")
    ->params(['@age' => 30, '@country' => 'Portugal'])
    ->find()
    ->toArray();

# query the top 5 documents as an array, with the
# document ID as the array key.
$res = \Phuze\PhpCosmos\QueryBuilder::instance()
    ->setCollection($collection)
    ->select("c.id, c.username")
    ->where("c.age > @age and c.country = @country")
    ->params(['@age' => 10, '@country' => 'Portugal'])
    ->limit(5)
    ->findAll(true)
    ->toArray('id');

# query a document using a collection alias and cross partition query
$res = \Phuze\PhpCosmos\QueryBuilder::instance()
    ->setCollection($collection)
    ->select("HelloWorld.id, HelloWorld.name")
    ->from("HelloWorld")
    ->where("HelloWorld.age > 30")
    ->findAll(true)
    ->toArray();
```

### Deleting Records

```php
# delete one document that matches criteria (single partition)
$res = \Phuze\PhpCosmos\QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->where("c.age > 30 and c.country = 'Portugal'")
    ->delete();

# delete all documents that match criteria (cross partition)
$res = \Phuze\PhpCosmos\QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->where("c.age > 20")
    ->deleteAll(true);
```
