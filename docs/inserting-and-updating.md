# Inserting and Updating

These examples use the `$db` and `$collection` from [Connecting](connecting.md).

`setPartitionKey()` tells `save()` and `delete()` which property holds the partition key, so they can read its value from the document. Queries and patches use `setPartitionValue()` instead.

## Inserting

```php
use Phuze\PhpCosmos\QueryBuilder;

# Insert a document into the "Users" collection, which is partitioned on country.
# save() returns the new document's _rid.
$rid = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->save(['id' => '1', 'name' => 'John Doe', 'age' => 22, 'country' => 'Canada']);

# Insert a document into a collection with a nested partition key.
# The key can be written as 'billing.country' or '/billing/country'.
$customers = $db->selectCollection('Customers', '/billing/country');

$customerRid = QueryBuilder::instance()
    ->setCollection($customers)
    ->setPartitionKey('billing.country')
    ->save([
        'id' => '2',
        'name' => 'Jane Doe',
        'billing' => ['country' => 'Canada']
    ]);
```

## Updating

`save()` with an existing document's `_rid` replaces the whole document. To change only some properties, see [Patching](patching.md).

```php
use Phuze\PhpCosmos\QueryBuilder;

$rid = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->save([
        '_rid'    => $rid, // John Doe's _rid, from the insert above
        'id'      => '1',
        'name'    => 'John Doe',
        'age'     => 23,
        'country' => 'Canada'
    ]);
```
