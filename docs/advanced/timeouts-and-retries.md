# Timeouts and retries

## Timeouts

| Option | Limits | Unit | Without a value |
| --- | --- | --- | --- |
| `timeout_connect` | The time to connect to a node | Seconds. A fraction is kept: `0.5` is 500 ms. | `0`, `''` and `null` give curl its default of 300 seconds |
| `timeout_query` | The time of a request. It is also the `max_execution_time` of the server. | Whole seconds. A fraction is rounded up: `0.5` is 1 second. | `0`, `''` and `null` mean no limit |

- A connection config without one of the options gets 2 seconds.
- A timeout is an int, a float or a numeric string. Another value throws an `InvalidArgumentException` when Laravel creates the connection:
  `The [timeout_query] option of the ClickHouse connection must be a number of seconds, [abc] given.`
- The timeouts apply to all nodes of a cluster, and to the pings that find a node that answers.

::: tip
Migration commands can need more time. An `ON CLUSTER` statement waits for all hosts. On ClickHouse 26.8 with little RAM, the delete of a rollback can take more than 2 seconds.
:::

## Retries

`retries` sends a request again when it does not get HTTP 200, for example after a network error:

```dotenv
CLICKHOUSE_RETRIES=2
CLICKHOUSE_RETRY_ON=unsent
```

- `retries` is `0` by default: one attempt. `1` is one attempt and one retry.
- `retry_on` selects the requests to send again:

| `retry_on` | Sends again |
| --- | --- |
| `any` (default) | Each failed request, also a request that timed out on the client and a request that ClickHouse answered with an error |
| `unsent` | Only a request that did not reach the server: the connection was refused, the host name was not found, or the connect timeout ended |

::: danger
With `any`, a write can run more than one time. A request that timed out on the client can continue on the server, and the retry sends it again.
On ClickHouse 24.8, with `retries` set to `2`, an `INSERT` that timed out stored its rows three times.
Use `unsent` on connections that write.
:::

- `retry_on` accepts any letter case. `null` and `''` are `any`. Another value throws an `InvalidArgumentException`, also when `retries` is `0`.
- In a [session](/advanced/sessions), a request is sent again only when it did not reach the server.
- [Parallel queries](/advanced/parallel-queries) send a failed request again in a later round.
- The smi2 client's `selectAsync()` with `executeAsync()`, and the files of `insertFiles()`, are sent one time.
