# php-cosmos

[![Tests](https://img.shields.io/github/actions/workflow/status/phuze/php-cosmos/tests.yml?branch=main&label=tests&logo=githubactions&logoColor=white)](https://github.com/phuze/php-cosmos/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/phuze/php-cosmos?logo=packagist&logoColor=white)](https://packagist.org/packages/phuze/php-cosmos)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phuze/php-cosmos/php?logo=php&logoColor=white)](#requirements)
[![Guzzle Version](https://img.shields.io/packagist/dependency-v/phuze/php-cosmos/guzzlehttp/guzzle?label=guzzle)](#requirements)
[![Total Downloads](https://img.shields.io/packagist/dt/phuze/php-cosmos)](https://packagist.org/packages/phuze/php-cosmos/stats)
[![License](https://img.shields.io/packagist/l/phuze/php-cosmos)](LICENSE)

A PHP client for Azure Cosmos DB.

## Installation

Install phuze/php-cosmos in your project:

```bash
composer require phuze/php-cosmos
```

To stay on 3.x, pin the version:

```bash
composer require phuze/php-cosmos:^3.0
```

### Requirements

- PHP 7.0 or later, with the `curl` and `json` extensions
- Guzzle 6, 7 or 8, whichever Composer picks for your PHP version:

| PHP version | Guzzle version |
| --- | --- |
| 7.0 to 7.2.4 | 6 |
| 7.2.5 to 7.3 | 6 or 7 |
| 7.4 and later, including 8.x | 6, 7 or 8 |

## Usage

```php
use Phuze\PhpCosmos\CosmosDb;
use Phuze\PhpCosmos\QueryBuilder;

# connect, and select a database and collection
$conn = new CosmosDb('https://myaccount.documents.azure.com', 'your-key');
$database = $conn->selectDB('databaseName');
$collection = $database->selectCollection('Users', '/country');

# find the users in Canada who are over 30
$users = QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionValue('Canada')
    ->where("c.age > @age")
    ->params(['@age' => 30])
    ->findAll()
    ->toArray();
```

See the [documentation](docs/README.md) for more examples:

- [Connecting](docs/connecting.md)
- [Inserting, Updating and Upserting](docs/inserting-and-updating.md)
- [Querying](docs/querying.md)
- [Patching](docs/patching.md)
- [Deleting](docs/deleting.md)
- [Rate Limiting (429)](docs/rate-limiting.md)
- [Logging and Debugging](docs/logging.md)

## Changelog

### v4.0.1
- Renamed variables and some method parameters for clarity, using camelCase consistently
- Cleaned up the doc blocks for a better IDE experience

### v4.0.0
This release changes some existing behavior. See [Upgrading from v3](#upgrading-from-v3).

- [Connections](docs/connecting.md) are now reused between requests, which makes them faster
- Requests now [time out](docs/connecting.md#timeouts-and-client-options) after 60 seconds
- Safe requests (reads, queries, creates, replaces and deletes) are retried once if the connection drops, but PATCH requests and stored procedures never are, since they aren't always safe to repeat
- New [PATCH support](docs/patching.md), for updating individual properties of a document
- New [`setRetryOptions()`](docs/rate-limiting.md#automatic-retries) to automatically retry rate-limited (429) requests (off by default)
- New [`setLogger()`](docs/logging.md) for logging requests and retries
- Added support for Guzzle 8
- Added a [test suite](#development)
- [Cross-partition queries](docs/querying.md#cross-partition-queries) now return all of their results, not just the first page
- Query parameters keep their type, so `true`, `false`, `null` and arrays work
- Partition key values with quotes, and numeric partition keys, now work
- Fixed `delete()` and `deleteAll()` with slashed partition keys, such as `/form/type` or `/vendorName`
- Fixed `save()` failing when no partition key is set
- Fixed `whereContains()` missing its closing parenthesis
- `whereContains()`, `whereStartsWith()`, `whereEndsWith()`, `whereIn()` and `whereNotIn()` now escape quotes for you
- Fixed `setPartitionValue('0')` being ignored
- Fixed `selectCollection()` when the partition key has no leading slash
- Fixed debug mode emptying responses
- Fixed deprecation warnings on newer versions of PHP and Guzzle
- Fixed a warning on PHP 7.0 to 7.2 when a query returns no documents
- Fixed support for PHP 7.0 and 7.1

#### Upgrading from v3
Most apps only need to change `phuze/php-cosmos` to `^4.0` in `composer.json`. Check each of these in case it applies to you:

- **Timeouts:** requests now give up after 60 seconds (v3 waited forever), so if you run queries that take longer, raise the limit:

  ```php
  $conn = new CosmosDb($host, $key);
  $conn->setHttpClientOptions(['timeout' => 120]); # in seconds
  ```

- **Partition values:** Cosmos DB treats the number `5` and the string `"5"` as different partition key values. v3 always sent a string, but v4 sends whatever you pass, so if your partition key values are strings and you pass an integer (e.g. an ID from another database), cast it:

  ```php
  $res = QueryBuilder::instance()
      ->setCollection($collection)
      ->setPartitionValue((string)$id)
      ->findAll()
      ->toArray();
  ```

- **Query parameters:** v3 turned `true`, `false` and `null` parameters into the strings `"1"`, `""` and `""`, so this query looked for the string `"1"` and never matched a document with `"active": true`. v4 sends the actual values, so it now works:

  ```php
  $res = QueryBuilder::instance()
      ->setCollection($collection)
      ->where("c.active = @active")
      ->params(['@active' => true]) # v3 sent "1", v4 sends true
      ->findAll(true)
      ->toArray();
  ```

  If your documents store flags as strings like `"1"`, pass a string instead:

  ```php
      ->params(['@active' => '1'])
  ```

- **Escaped values:** `whereContains()`, `whereStartsWith()`, `whereEndsWith()`, `whereIn()` and `whereNotIn()` now escape quotes and backslashes for you, so pass values as they are, or they'll be escaped twice:

  ```php
  # v3 needed quotes escaped by hand
  ->whereStartsWith('c.name', "O\\'Brien")

  # v4 escapes them for you
  ->whereStartsWith('c.name', "O'Brien")
  ```

- **Subclasses:** to support numeric partition keys, `$partitionValue` (called `$partitionKey` in v3) in `createDocument()`, `replaceDocument()` and `deleteDocument()`, and `$document` in `findPartitionValue()`, no longer have a type. If you extend `CosmosDb` or `QueryBuilder` and override any of these, remove the type from your override too, or PHP will throw a fatal error:

  ```diff
  - public function createDocument(..., string $partitionKey = null, ...)
  + public function createDocument(..., $partitionValue = null, ...)
  ```

- **Creating documents:** if the connection drops after Cosmos DB has saved a new document, the automatic retry fails with a 409 Conflict, so if you handle errors from `save()`, treat a 409 as "this document may already exist" rather than a plain failure

### v3.0.6
- Fixed a warning on PHP 7.2 when a query returns no documents

### v3.0.5
- Fixed `whereContains()` missing its closing parenthesis
- Fixed `save()` throwing a `TypeError` when no partition key is set, a regression in 3.0.4
- Fixed `delete()` and `save()` with slashed partition keys, such as `/form/type` or `/vendorName`
- Fixed `selectCollection()` when the partition key has no leading slash
- Fixed debug mode emptying responses
- Fixed deprecation warnings on PHP 8.2+ and Guzzle 7.11+

### v3.0.4
- `save()` and `deleteAll()` now use the value from `setPartitionValue()`, even when no partition key is set

### v3.0.3
- Removed typed class properties, which need PHP 7.4, so the library loads on older PHP 7 versions

### v3.0.2
- Fixed every request failing with "Call to a member function getBody() on string"

### v3.0.1
- Fixed `composer.json` requiring PHP 8.0, which stopped PHP 7 from installing the library

### v3.0.0
- Restored support for PHP 7.x, so this library can be used with both 7.x and 8.x
- Improved how nested partition keys are handled
- Improved how partition values are matched
- Fixed an issue that prevented document deletion when a container used a nested partition key
- Fixed an issue with `partitionkeyrangeid` headers when a cross-partition query needs to be retried with PK ranges

## Notes

- Supports PHP 7.0 and later, so it also runs on legacy systems that can't move to PHP 8 yet
- Talks to Cosmos DB through Microsoft's REST API (version `2018-12-31`), so it only needs Guzzle and the `curl` extension, not an SDK
- Based on [AzureDocumentDB-PHP](https://github.com/cocteau666/AzureDocumentDB-PHP) and [CosmosDb](https://github.com/jupitern/cosmosdb)
- Some [cross-partition queries](docs/querying.md#cross-partition-queries) (e.g. those with `ORDER BY`, `TOP` or aggregates) can't be served by the Cosmos DB gateway, so they're run against each partition key range in turn, and `ORDER BY` and `TOP` apply within each range rather than across the whole result

## Development

```bash
composer install
composer test
```

The tests send their requests to a fake Cosmos DB, so no Azure account is needed. They're plain PHP rather than PHPUnit, so the same tests run on every supported PHP version. Each file in `tests/cases` covers one area, and any notice, warning or deprecation raised by the library fails the run.

GitHub Actions runs the tests on every push and pull request, on each PHP version from 7.0 to 8.5, with every Guzzle version that PHP version supports. Releases are only tagged from a commit that passes.

## Contributing

Bug reports, fixes and improvements are welcome.

**Reporting a bug or requesting a feature:** [open an issue](https://github.com/phuze/php-cosmos/issues) with your PHP and Guzzle versions, what you did, and what you expected to happen.

**Submitting a change:**

1. Fork the repository and create a branch from `main`
2. Make your change, keeping it compatible with PHP 7.0 (no typed properties, nullable types, arrow functions or other syntax newer than PHP 7.0)
3. Add or update a test in `tests/cases` that covers it
4. Run `composer test` and make sure everything passes
5. Open a pull request against `main`, explaining what changed and why

GitHub Actions runs the tests on your pull request, and it's only merged once they pass. If your change affects how the library is used, please also update the changelog above and the relevant page in [docs/](docs/README.md).
