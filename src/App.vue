<template>
	<NcContent app-name="moviedb">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem :name="t('moviedb', 'Dashboard')"
					:to="{ name: 'dashboard' }"
					:exact="true">
					<template #icon>
						<ViewDashboard :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('moviedb', 'My Movies')"
					:to="{ name: 'movies' }"
					:exact="true">
					<template #icon>
						<Movie :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('moviedb', 'Watchlist')"
					:to="{ name: 'watchlist' }">
					<template #icon>
						<PlaylistPlay :size="20" />
					</template>
					<template #counter>
						<NcCounterBubble v-if="watchlistCount > 0" :count="watchlistCount" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('moviedb', 'Add Movie')"
					:to="{ name: 'add-movie' }">
					<template #icon>
						<Plus :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('moviedb', 'Settings')"
					:to="{ name: 'settings' }">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<router-view />
		</NcAppContent>
	</NcContent>
</template>

<script>
import {
	NcContent,
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppContent,
	NcCounterBubble,
} from '@nextcloud/vue'

import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import Movie from 'vue-material-design-icons/Movie.vue'
import PlaylistPlay from 'vue-material-design-icons/PlaylistPlay.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Cog from 'vue-material-design-icons/Cog.vue'

import { useWatchlistStore } from './stores/watchlist.js'
import { usePlatformsStore } from './stores/platforms.js'
import { useSettingsStore } from './stores/settings.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		NcCounterBubble,
		ViewDashboard,
		Movie,
		PlaylistPlay,
		Plus,
		Cog,
	},
	setup() {
		const watchlistStore = useWatchlistStore()
		const platformsStore = usePlatformsStore()
		const settingsStore = useSettingsStore()
		return { watchlistStore, platformsStore, settingsStore }
	},
	computed: {
		watchlistCount() {
			return this.watchlistStore.total
		},
	},
	created() {
		// Load initial data
		this.watchlistStore.fetchAll()
		this.platformsStore.fetchAll()
		this.settingsStore.fetch()
	},
}
</script>

<style lang="scss">
#moviedb {
    height: 100%;
}

// Offset content to avoid overlapping with the navigation toggle button
#app-content-vue {
    padding-left: 36px;
}
</style>
