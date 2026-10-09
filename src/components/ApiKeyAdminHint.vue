<template>
	<p v-if="isAdmin || !hasInstanceApiKey" class="api-key-admin-hint">
		<a v-if="isAdmin" :href="adminSettingsUrl">
			{{ hasInstanceApiKey ? t('moviedb', 'Manage the key for all users') : t('moviedb', 'Set up a key for all users') }}
		</a>
		<template v-else>
			{{ t('moviedb', 'Your administrator can also set up a key for everyone.') }}
		</template>
	</p>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { useSettingsStore } from '../stores/settings.js'

/**
 * Hint about the instance-wide TMDB key, shown under the "TMDB API Key
 * Required" messages and in Settings: admins get a link to set up or manage
 * the key, everyone else learns they can ask for one (unless it already exists).
 */
export default {
	name: 'ApiKeyAdminHint',

	setup() {
		const settingsStore = useSettingsStore()
		return { settingsStore }
	},

	computed: {
		isAdmin() {
			return this.settingsStore.isAdmin
		},

		hasInstanceApiKey() {
			return this.settingsStore.hasInstanceApiKey
		},

		adminSettingsUrl() {
			return generateUrl('/settings/admin/moviedb')
		},
	},
}
</script>

<style lang="scss" scoped>
.api-key-admin-hint {
	margin-top: 8px;

	a {
		text-decoration: underline;
	}
}
</style>
