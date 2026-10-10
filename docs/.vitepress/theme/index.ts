import { h } from 'vue'
import DefaultTheme from 'vitepress/theme'
import VersionBanner from './VersionBanner.vue'
import './custom.css'

export default {
  extends: DefaultTheme,
  Layout() {
    return h(DefaultTheme.Layout, null, {
      'doc-before': () => h(VersionBanner),
      'home-hero-before': () => h(VersionBanner),
    })
  },
}
