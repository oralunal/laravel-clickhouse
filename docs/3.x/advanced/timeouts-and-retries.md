# Timeouts and retries

## Timeouts

| Option | `.env` variable | Default | Description |
| --- | --- | --- | --- |
| `timeout_connect` | `CLICKHOUSE_TIMEOUT_CONNECT` | `2` | Seconds to wait for a connection |
| `timeout_query` | `CLICKHOUSE_TIMEOUT_QUERY` | `2` | Seconds that a query can take |

## Retries

The package can send a request again when the response is not 200, for example after a network error:

```dotenv
CLICKHOUSE_RETRIES=2
```

- `retries` is optional. The default is `0`: one attempt and no retry.
- `1` gives one attempt and one retry, two attempts in total.

::: danger
3.x sends each failed request again: also a request that timed out on the client, and a request that ClickHouse answered with an error.
A request that timed out on the client can continue on the server, so a write can run more than one time. On ClickHouse 24.8, with `retries` set to `2`, an `INSERT` that timed out stored its rows three times.
4.0 adds the `retry_on` option, which sends again only the requests that did not reach the server.
:::
