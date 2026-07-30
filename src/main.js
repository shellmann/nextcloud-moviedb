import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { translate, translatePlural } from '@nextcloud/l10n'
import App from './App.vue'
import router from './router.js'

const app = createApp(App)
const pinia = createPinia()

// Global properties for translations (replaces Vue.mixin)
app.config.globalProperties.t = (appName, text) => translate(appName, text)
app.config.globalProperties.n = (appName, singular, plural, count) => translatePlural(appName, singular, plural, count)

app.use(pinia)
app.use(router)

app.mount('#moviedb')
