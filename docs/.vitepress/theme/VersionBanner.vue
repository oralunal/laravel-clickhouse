<script setup lang="ts">
import { computed } from 'vue'
import { useData } from 'vitepress'

/**
 * A notice at the top of each page of an earlier version: the version that the page describes, and a link to the
 * documentation of the latest version.
 */
const { localeIndex } = useData()
const version = computed(() => (localeIndex.value === 'root' ? null : localeIndex.value))
</script>

<template>
  <div v-if="version" class="version-banner custom-block warning">
    <p>
      This page describes <strong>{{ version }}</strong>, an earlier version.
      The latest version is 4.x: <a href="/">read its documentation</a> or
      <a href="/getting-started/upgrading">upgrade to 4.x</a>.
    </p>
  </div>
</template>

<style scoped>
.version-banner {
  margin: 0 0 24px;
}

.VPHome .version-banner {
  margin: 16px 24px 0;
}

@media (min-width: 960px) {
  .VPHome .version-banner {
    margin: 16px auto 0;
    max-width: 1152px;
  }
}
</style>
