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

See the [documentation](docs/README.md) for examples:

- [Connecting](docs/connecting.md)
- [Inserting and Updating](docs/inserting-and-updating.md)
- [Querying](docs/querying.md)
- [Patching](docs/patching.md)
- [Deleting](docs/deleting.md)
- [Rate Limiting (429)](docs/rate-limiting.md)
- [Logging and Debugging](docs/logging.md)
