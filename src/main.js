import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@nextcloud/dialogs/style.css'
import App from './App.vue'
import router from './router.js'
import { translateText, translateTextPlural } from './utils/translations.js'

const app = createApp(App)
const pinia = createPinia()

// Global properties for translations (replaces Vue.mixin)
app.config.globalProperties.t = translateText
app.config.globalProperties.n = translateTextPlural

app.use(pinia)
app.use(router)

app.mount('#moviedb')
