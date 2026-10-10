import { defineConfig } from 'vitepress'

export default defineConfig({
  lang: 'en-US',
  title: 'laravel-clickhouse',
  description: 'ClickHouse for Laravel: models, query builders, schema builder, migrations, sessions, parallel queries and clusters.',
  cleanUrls: true,
  lastUpdated: true,

  sitemap: {
    hostname: 'https://laravel-clickhouse.oralunal.com',
  },

  themeConfig: {
    nav: [
      { text: 'Guide', link: '/getting-started/introduction', activeMatch: '^/(getting-started|models|query-builder|schema|advanced)/' },
      { text: 'Reference', link: '/reference/clickhouse-versions', activeMatch: '^/reference/' },
      {
        text: '4.x',
        items: [
          { text: 'Changelog', link: 'https://github.com/oralunal/laravel-clickhouse/blob/master/CHANGELOG.md' },
          { text: 'Upgrade guide', link: '/getting-started/upgrading' },
        ],
      },
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
