import { defineConfig, type DefaultTheme } from 'vitepress'

const CHANGELOG = 'https://github.com/oralunal/laravel-clickhouse/blob/master/CHANGELOG.md'

/**
 * The documented versions. The latest version is at the root of the site. Each earlier major version has a
 * directory of its own, which is a locale of VitePress: it has its own title, menu, sidebar and search index.
 */
const VERSIONS = [
  { key: 'root', label: '4.x (latest)', menu: '4.x', link: '/', title: 'laravel-clickhouse' },
  { key: '3.x', label: '3.x', menu: '3.x', link: '/3.x/', title: 'laravel-clickhouse 3.x' },
  { key: '2.x', label: '2.x', menu: '2.x', link: '/2.x/', title: 'phpclickhouse-laravel 2.x' },
  { key: '1.x', label: '1.x', menu: '1.x', link: '/1.x/', title: 'phpclickhouse-laravel 1.x' },
] as const

function versionMenu(current: string): DefaultTheme.NavItem {
  return {
    text: current,
    items: [
      {
        text: 'Versions',
        items: VERSIONS.map((version) => ({ text: version.label, link: version.link })),
      },
      { text: 'Changelog', link: CHANGELOG },
      { text: 'Upgrade guide', link: '/getting-started/upgrading' },
    ],
  }
}

/**
 * The menu and sidebar of an earlier version. Its pages have the same paths in each version directory.
 */
function earlierVersion(prefix: string, menu: string): DefaultTheme.Config {
  return {
    nav: [
      { text: 'Guide', link: `${prefix}getting-started/introduction`, activeMatch: `^${prefix}(getting-started|models|query-builder|schema|advanced)/` },
      { text: 'Reference', link: `${prefix}reference/known-limitations`, activeMatch: `^${prefix}reference/` },
      versionMenu(menu),
    ],
    sidebar: [
      {
        text: 'Get started',
        items: [
          { text: 'Introduction', link: `${prefix}getting-started/introduction` },
          { text: 'Installation', link: `${prefix}getting-started/installation` },
          { text: 'Upgrade', link: `${prefix}getting-started/upgrading` },
        ],
      },
      {
        text: 'Models',
        items: [
          { text: 'Define a model', link: `${prefix}models/defining-models` },
          { text: 'Insert rows', link: `${prefix}models/inserting-rows` },
        ],
      },
      {
        text: 'Query builder',
        items: [
          { text: 'Basics', link: `${prefix}query-builder/basics` },
          { text: 'Updates and deletions', link: `${prefix}query-builder/writing-data` },
        ],
      },
      {
        text: 'Schema and migrations',
        items: [
          { text: 'Migrations', link: `${prefix}schema/migrations` },
          { text: 'Migration commands', link: `${prefix}schema/migration-commands` },
        ],
      },
      {
        text: 'Advanced',
        items: [
          { text: 'Multiple connections', link: `${prefix}advanced/multiple-connections` },
          { text: 'Clusters', link: `${prefix}advanced/clusters` },
          { text: 'Timeouts and retries', link: `${prefix}advanced/timeouts-and-retries` },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Known limitations', link: `${prefix}reference/known-limitations` },
          { text: 'Release notes', link: `${prefix}reference/release-notes` },
        ],
      },
    ],
  }
}

export default defineConfig({
  lang: 'en-US',
  title: 'laravel-clickhouse',
  description: 'ClickHouse for Laravel: models, query builders, schema builder, migrations, sessions, parallel queries and clusters.',
  cleanUrls: true,
  lastUpdated: true,

  sitemap: {
    hostname: 'https://laravel-clickhouse.oralunal.com',
  },

  locales: Object.fromEntries(VERSIONS.map((version) => [
    version.key,
    {
      label: version.label,
      lang: 'en-US',
      link: version.link,
      title: version.title,
      themeConfig: version.key === 'root' ? {} : earlierVersion(version.link, version.menu),
    },
  ])),

  themeConfig: {
    nav: [
      { text: 'Guide', link: '/getting-started/introduction', activeMatch: '^/(getting-started|models|query-builder|schema|advanced)/' },
      { text: 'Reference', link: '/reference/clickhouse-versions', activeMatch: '^/reference/' },
      versionMenu('4.x'),
    ],

    sidebar: [
      {
        text: 'Get started',
        items: [
          { text: 'Introduction', link: '/getting-started/introduction' },
          { text: 'Installation', link: '/getting-started/installation' },
          { text: 'Configuration', link: '/getting-started/configuration' },
          { text: 'Upgrade', link: '/getting-started/upgrading' },
        ],
      },
      {
        text: 'Models',
        items: [
          { text: 'Define a model', link: '/models/defining-models' },
          { text: 'Insert rows', link: '/models/inserting-rows' },
          { text: 'Eloquent models', link: '/models/eloquent' },
        ],
      },
      {
        text: 'Query builder',
        items: [
          { text: 'Basics', link: '/query-builder/basics' },
          { text: 'Conditions', link: '/query-builder/conditions' },
          { text: 'ClickHouse SQL', link: '/query-builder/clickhouse-sql' },
          { text: 'Read results', link: '/query-builder/reading-results' },
          { text: 'Updates and deletions', link: '/query-builder/writing-data' },
          { text: 'Dates and times', link: '/query-builder/dates' },
          { text: "Laravel's query builder", link: '/query-builder/laravel-query-builder' },
        ],
      },
      {
        text: 'Schema and migrations',
        items: [
          { text: 'Migrations', link: '/schema/migrations' },
          { text: "Laravel's schema builder", link: '/schema/schema-builder' },
          { text: 'Schema introspection', link: '/schema/introspection' },
          { text: 'Migration commands', link: '/schema/migration-commands' },
        ],
      },
      {
        text: 'Advanced',
        items: [
          { text: 'Raw SQL', link: '/advanced/raw-sql' },
          { text: 'Sessions and temporary tables', link: '/advanced/sessions' },
          { text: 'Parallel queries', link: '/advanced/parallel-queries' },
          { text: 'Multiple connections', link: '/advanced/multiple-connections' },
          { text: 'Clusters', link: '/advanced/clusters' },
          { text: 'Timeouts and retries', link: '/advanced/timeouts-and-retries' },
          { text: 'Tests', link: '/advanced/testing' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'ClickHouse versions', link: '/reference/clickhouse-versions' },
          { text: 'Known limitations', link: '/reference/known-limitations' },
          { text: 'Helpers, enums and exceptions', link: '/reference/helpers' },
          { text: 'API reference', link: '/reference/api' },
          { text: 'Coding-agent skills', link: '/reference/agent-skills' },
          { text: 'Documentation versions', link: '/reference/versions' },
          { text: 'Contribute', link: '/reference/contributing' },
        ],
      },
    ],

    outline: [2, 3],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/oralunal/laravel-clickhouse' },
    ],

    search: {
      provider: 'local',
    },

    editLink: {
      pattern: 'https://github.com/oralunal/laravel-clickhouse/edit/master/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
    },
  },
})
