# Inserting and Updating

These examples use the `$database` and `$collection` from [Connecting](connecting.md).

`setPartitionKey()` tells `save()` and `delete()` which property holds the partition key, so they can read its value from the document. Queries and patches use `setPartitionValue()` instead.

## Inserting

```php
use Phuze\PhpCosmos\QueryBuilder;

# Insert a document into the "Users" collection, which is partitioned on country.
# save() returns the new document's _rid.
$rid = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->save([
        'id'      => '1',
        'name'    => 'John Doe',
        'age'     => 22,
        'country' => 'Canada'
    ]);

# Insert a document into a collection with a nested partition key.
# The key can be written as 'billing.country' or '/billing/country'.
$customers = $database->selectCollection('Customers', '/billing/country');

$customerRid = QueryBuilder::instance()
    ->setCollection($customers)
    ->setPartitionKey('billing.country')
    ->save([
        'id'      => '2',
        'name'    => 'Jane Doe',
        'billing' => ['country' => 'Canada']
    ]);
```

## Updating

`save()` with an existing document's `_rid` replaces the whole document. To replace a document without its `_rid`, see [Upserting](#upserting). To change only some properties, see [Patching](patching.md).

```php
use Phuze\PhpCosmos\QueryBuilder;

$rid = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->save([
        '_rid'    => $rid, # John Doe's _rid, from the insert above
        'id'      => '1',
        'name'    => 'John Doe',
        'age'     => 23,
        'country' => 'Canada'
    ]);
```

## Upserting

`upsert()` creates a document, or replaces the one with the same `id` in the same partition. Unlike `save()`, it doesn't need the document's `_rid`, so it suits documents whose `id` you already know. It returns the document's `_rid`.

```php
use Phuze\PhpCosmos\QueryBuilder;

$query = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country');

# No document in the Canada partition has id 3, so this creates one.
$query->upsert([
    'id'      => '3',
    'name'    => 'Mary Major',
    'age'     => 34,
    'country' => 'Canada'
]);

# Now one does, so this replaces it.
$query->upsert([
    'id'      => '3',
    'name'    => 'Mary Major',
    'age'     => 35,
    'country' => 'Canada'
]);
```
