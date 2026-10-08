<template>
	<p class="api-key-admin-hint">
		<a v-if="isAdmin" :href="adminSettingsUrl">{{ t('moviedb', 'Set up a key for all users') }}</a>
		<template v-else>
			{{ t('moviedb', 'Your administrator can also set up a key for everyone.') }}
		</template>
	</p>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { useSettingsStore } from '../stores/settings.js'

/**
 * Hint under the "TMDB API Key Required" messages: admins get a link to set
 * an instance-wide key, everyone else learns they can ask for one.
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
