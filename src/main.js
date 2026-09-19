import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import router from './router.js'
import { translateText, translateTextPlural } from './utils/translations.js'

import '@nextcloud/dialogs/style.css'

const app = createApp(App)
const pinia = createPinia()

// Global properties for translations (replaces Vue.mixin)
app.config.globalProperties.t = translateText
app.config.globalProperties.n = translateTextPlural

app.use(pinia)
app.use(router)

app.mount('#moviedb')
