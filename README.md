# php-cosmos

PHP wrapper for Azure Cosmos DB

## Installation

Include phuze/php-cosmos in your project, by adding it to your composer.json file.

```php
{
    "require": {
        "phuze/php-cosmos": "3.*"
    }
}
```

## Changelog

### v3.0.0
- restore support for PHP 7.x -- this library can be used with both 7.x and 8.x
- fixed an issue preventing document deletion from collections with nested partition keys
- fixed an issue with partitionkeyrangeid headers, when a cross-partition query needs to be retried with PK ranges

### v2.6.0
- code refactor. min PHP verion supported is now 8.0
- selectCollection no longer creates a colletion if not exist. use createCollection for that
- bug fixes

### v2.5.0
- support partitioned queries using new method "setPartitionValue()"
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

## Note

Based on [AzureDocumentDB-PHP](https://github.com/cocteau666/AzureDocumentDB-PHP) and [CosmosDb](https://github.com/jupitern/cosmosdb).

## Usage

### Connecting

```php
$conn = new \Phuze\PhpCosmos\CosmosDb('hostName', 'primaryKey');
$conn->setHttpClientOptions(['verify' => false]); # optional: set guzzle client options.
$db = $conn->selectDB('dbName');
$collection = $db->selectCollection('collectionName');

# if a collection does not exist, it will be created when you
# attempt to select the collection. however, if you have created
# your database with shared throughput, then all collections require a partition key.
# selectCollection() supports a second parameter for this purpose.
$conn = new \Phuze\PhpCosmos\CosmosDb('hostName', 'primaryKey');
$conn->setHttpClientOptions(['verify' => false]); # optional: set guzzle client options.
$db = $conn->selectDB('dbName');
$collection = $db->selectCollection('collectionName', 'myPartitionKey');
```

### Inserting Records

```php
use \Phuze\PhpCosmos\QueryBuilder;

# connect
$conn = new \Phuze\PhpCosmos\CosmosDb('host', 'pk');
$conn->setHttpClientOptions(['verify' => false]); // optional: set guzzle client options.
$db = $conn->selectDB('dbName');
$collection = $db->selectCollection('collectionName');

# consider a existing collection called "Users" with a partition key "country"

# insert a record
$rid = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->save(['id' => '1', 'name' => 'John Doe', 'age' => 22, 'country' => 'Portugal']);

# insert a record against a collection with a nested partition key
$rid = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('billing.country')
    ->save(['id' => '2', 'name' => 'Jane doe', 'age' => 35, 'billing' => ['country' => 'Portugal']);
```

### Updating Records

```php
# update a record
$rid = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->save(["_rid" => $rid, 'id' => '2', 'name' => 'Jane Doe Something', 'age' => 36, 'country' => 'Portugal']);
```

### Querying Records

```php
# query a document and return it as an array
$res = QueryBuilder::instance()
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
$res = QueryBuilder::instance()
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
$res = QueryBuilder::instance()
    ->setCollection($collection)
    ->select("c.id, c.username")
    ->where("c.age > @age and c.country = @country")
    ->params(['@age' => 10, '@country' => 'Portugal'])
    ->limit(5)
    ->findAll(true)
    ->toArray('id');

# query a document using a collection alias and cross partition query
$res = QueryBuilder::instance()
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
$res = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->where("c.age > 30 and c.country = 'Portugal'")
    ->delete();

# delete all documents that match criteria (cross partition)
$res = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->where("c.age > 20")
    ->deleteAll(true);
```
