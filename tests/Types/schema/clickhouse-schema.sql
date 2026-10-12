-- ClickHouse schema of the connection: clickhouse

CREATE TABLE events
(
    `id` UInt64,
    `counter` Int64,
    `small` UInt8,
    `signed` Int32,
    `name` String,
    `note` Nullable(String),
    `code` LowCardinality(String),
    `fixed` FixedString(16),
    `price` Decimal(10, 2),
    `ratio` Float64,
    `flag` Bool,
    `happened_at` DateTime64(3, 'UTC'),
    `day` Date,
    `uuid` UUID,
    `ip` IPv4,
    `status` Enum8('new' = 1, 'done' = 2),
    `tags` Array(String),
    `attributes` Map(String, UInt8),
    `pair` Tuple(a String, b UInt8),
    `payload` String DEFAULT '{}',
    `big` Int128,
    `nested.k` Array(String),
    `nested.v` Array(UInt8),
    `maybe_tags` Array(Nullable(String)),
    `computed` UInt64 MATERIALIZED id * 2,
    `alias_col` String ALIAS name
)
ENGINE = ReplacingMergeTree(counter)
PARTITION BY toYYYYMM(happened_at)
ORDER BY (id, day)
TTL day + toIntervalYear(1)
SETTINGS index_granularity = 8192
COMMENT 'events';

CREATE TABLE migrations
(
    `id` UInt32,
    `migration` String,
    `batch` UInt32
)
ENGINE = MergeTree
ORDER BY id
SETTINGS index_granularity = 8192;

CREATE MATERIALIZED VIEW events_by_day
(
    `day` Date,
    `c` UInt64
)
ENGINE = SummingMergeTree
ORDER BY day
SETTINGS index_granularity = 8192
AS SELECT
    day,
    count() AS c
FROM events
GROUP BY day;

INSERT INTO `migrations` VALUES
(1,'2026_01_01_000000_create_events_table',1);

CREATE DICTIONARY users_dict
(
    `id` UInt64,
    `name` String
)
PRIMARY KEY id
SOURCE(CLICKHOUSE(TABLE 'users'))
LIFETIME(MIN 0 MAX 300)
LAYOUT(HASHED());

CREATE VIEW events_view
(
    `id` UInt64,
    `name` Nullable(String),
    `ratio` Float32
)
AS SELECT id, note AS name, toFloat32(ratio) AS ratio FROM events;
