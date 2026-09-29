# Patching

A patch changes individual properties of a document without sending the whole document. These examples patch John Doe's document from [Inserting and Updating](inserting-and-updating.md).

```php
use Phuze\PhpCosmos\QueryBuilder;

# John Doe's document is in the Canada partition.
$query = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionValue('Canada');

$rid = $query->patch($rid, [
    $query->getPatchOpSet('/name', 'John Smith'),
    $query->getPatchOpIncrement('/age', 1),
    $query->getPatchOpAdd('/tags', ['vip']), # Adds a new tags property.
]);

# A conditional patch is only applied if the document matches where(). If it
# doesn't, Cosmos DB responds with 412 Precondition Failed, thrown as a ClientException.
$query = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionValue('Canada')
    ->where("c.age < 40");

$rid = $query->patch($rid, [
    $query->getPatchOpAdd('/tags/-', 'under-40'), # Appends to the tags array.
]);
```

## Operations

| Operation | What it does |
| --- | --- |
| `getPatchOpAdd($path, $value)` | Adds a property, or inserts into an array. Replaces the property if it already exists. |
| `getPatchOpSet($path, $value)` | Sets a property, creating it if it doesn't exist. |
| `getPatchOpReplace($path, $value)` | Replaces a property. Fails if it doesn't exist. |
| `getPatchOpRemove($path)` | Removes a property or array element. Fails if it doesn't exist. |
| `getPatchOpIncrement($path, $value)` | Adds to a number. Use a negative value to subtract. |
| `getPatchOpMove($fromPath, $toPath)` | Moves a property to another path. |

## Things to Know

- A patch can have up to 10 operations
- Paths are [JSON Pointers](https://datatracker.ietf.org/doc/html/rfc6901), such as `/billing/country`
- An array index is a number, such as `/tags/0`, and `/tags/-` means the end of the array
- Within a property name, write `~` as `~0` and `/` as `~1`
- A condition can't use `params()`, so write its values into the `where()` string
- A patch that fails with a network error is not retried, since operations such as increment aren't safe to apply twice
