# Logging and Debugging

Pass any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger, such as Monolog:

```php
$conn->setLogger($logger);
```

| Level | What's logged |
| --- | --- |
| `debug` | Every request, with its `method`, `path`, `status`, `duration_ms`, `request_charge` and `activity_id`. |
| `debug` | Every query, with its text and parameters. |
| `info` | A cross-partition query falling back to one query per partition key range. |
| `warning` | A request being retried after a network error or a 429. |

The `request_charge` is the number of request units the request used, and the `activity_id` identifies the request if you need to raise it with Azure support. Query logs include parameter values, so leave debug-level logging off if those values are sensitive.

## Debug Mode

For quick debugging without a logger, echo every request and response to the output:

```php
$conn->debug = true;
```
