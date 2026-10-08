import { createApp } from 'vue'
import AdminSettings from './views/AdminSettings.vue'
import { translateText, translateTextPlural } from './utils/translations.js'

import '@nextcloud/dialogs/style.css'

// Admin settings page (Administration settings → MovieDB), see lib/Settings/Admin.php
const app = createApp(AdminSettings)

app.config.globalProperties.t = translateText
app.config.globalProperties.n = translateTextPlural

app.mount('#moviedb-admin-settings')
