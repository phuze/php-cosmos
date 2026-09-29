# Deleting

These examples use the `$collection` and `$customers` collections from [Inserting, Updating and Upserting](inserting-and-updating.md).

```php
use Phuze\PhpCosmos\QueryBuilder;

# Delete the first document that matches. The where() filters on the
# partition key, so this stays within a single partition. Returns true
# if a document was deleted, or false if nothing matched.
$deleted = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->where("c.age > 30 and c.country = 'Canada'")
    ->delete();

# Delete every document that matches, across all partitions.
QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionKey('country')
    ->where("c.age > 20")
    ->deleteAll(true);

# Nested partition keys work too, written either way.
$deleted = QueryBuilder::instance()
    ->setCollection($customers)
    ->setPartitionKey('/billing/country')
    ->where("c.id = '2'")
    ->delete(true);
```
