# Querying

These examples use the `$collection` from [Connecting](connecting.md).

```php
use Phuze\PhpCosmos\QueryBuilder;

# Find one document across all partitions (true = cross partition),
# and return it as an array.
$res = QueryBuilder::instance()
    ->setCollection($collection)
    ->select("c.id, c.name")
    ->where("c.age > @age and c.country = @country")
    ->params(['@age' => 30, '@country' => 'Canada'])
    ->find(true)
    ->toArray();

# Find one document in a known partition. Querying a single
# partition is cheaper and faster than a cross-partition query.
$res = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionValue('Canada')
    ->select("c.id, c.name")
    ->where("c.age > @age")
    ->params(['@age' => 30])
    ->find()
    ->toArray();

# Find the first 5 documents in a partition, as an array keyed by
# document id. Note: across partitions, limit() applies to each
# partition key range separately (see below).
$res = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionValue('Canada')
    ->select("c.id, c.name")
    ->where("c.age > @age")
    ->params(['@age' => 10])
    ->limit(5)
    ->findAll()
    ->toArray('id');

# Parameters keep their type, so booleans, nulls and arrays work.
$res = QueryBuilder::instance()
    ->setCollection($collection)
    ->where("c.active = @active and ARRAY_CONTAINS(@countries, c.country)")
    ->params(['@active' => true, '@countries' => ['Canada', 'United States']])
    ->findAll(true)
    ->toArray();

# Query across partitions using a collection alias.
$res = QueryBuilder::instance()
    ->setCollection($collection)
    ->select("HelloWorld.id, HelloWorld.name")
    ->from("HelloWorld")
    ->where("HelloWorld.age > 30")
    ->findAll(true)
    ->toArray();
```

## Reading a Document

If you have a document's `_rid`, reading it directly is cheaper and faster than a query. Pass its partition value when the collection is partitioned.

```php
# John Doe's _rid, from Inserting and Updating.
$doc = json_decode($collection->getDocument($rid, 'Canada'));
```

## Cross-Partition Queries

Some cross-partition queries (e.g. those with `ORDER BY`, `TOP` or aggregates) can't be served by the Cosmos DB gateway, so they're run against each partition key range in turn, and `ORDER BY` and `TOP` apply within each range rather than across the whole result.
