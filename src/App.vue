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
				<NcAppNavigationItem :name="t('moviedb', 'Movies')"
					:to="{ name: 'movies' }"
					:exact="true">
					<template #icon>
						<Movie :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('moviedb', 'TV Shows')"
					:to="{ name: 'series' }"
					:exact="true">
					<template #icon>
						<Television :size="20" />
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
				<NcAppNavigationItem :name="t('moviedb', 'Libraries')"
					:to="{ name: 'libraries' }">
					<template #icon>
						<BookshelfIcon :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('moviedb', 'Settings')"
					:to="{ name: 'settings' }">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<div class="library-switcher">
					<NcSelect v-if="libraries.length > 1"
						:model-value="activeLibraryOption"
						:options="libraryOptions"
						:clearable="false"
						:placeholder="t('moviedb', 'Select library')"
						:input-label="t('moviedb', 'Select library')"
						label="label"
						track-by="id"
						@update:model-value="onLibraryChange" />
					<div v-if="!activeCanEdit" class="readonly-badge">
						<EyeOutline :size="14" />
						{{ t('moviedb', 'Read-only') }}
					</div>
				</div>
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
	NcSelect,
} from '@nextcloud/vue'

import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import Movie from 'vue-material-design-icons/Movie.vue'
import Television from 'vue-material-design-icons/Television.vue'
import PlaylistPlay from 'vue-material-design-icons/PlaylistPlay.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import BookshelfIcon from 'vue-material-design-icons/Bookshelf.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'

import { translate as t } from '@nextcloud/l10n'

import { useWatchlistStore } from './stores/watchlist.js'
import { usePlatformsStore } from './stores/platforms.js'
import { useSettingsStore } from './stores/settings.js'
import { useLibrariesStore } from './stores/libraries.js'
import { useMoviesStore } from './stores/movies.js'
import { useSeriesStore } from './stores/series.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		NcCounterBubble,
		NcSelect,
		ViewDashboard,
		Movie,
		Television,
		PlaylistPlay,
		Cog,
		BookshelfIcon,
		EyeOutline,
	},
	setup() {
		const watchlistStore = useWatchlistStore()
		const platformsStore = usePlatformsStore()
		const settingsStore = useSettingsStore()
		const librariesStore = useLibrariesStore()
		const moviesStore = useMoviesStore()
		const seriesStore = useSeriesStore()
		return { watchlistStore, platformsStore, settingsStore, librariesStore, moviesStore, seriesStore }
	},
	computed: {
		watchlistCount() {
			return this.watchlistStore.total
		},
		libraries() {
			return this.librariesStore.libraries
		},
		activeLibraryId() {
			return this.librariesStore.activeLibraryId
		},
		activeCanEdit() {
			return this.librariesStore.activeCanEdit
		},
		libraryOptions() {
			return this.libraries.map(lib => ({
				id: lib.id,
				label: lib.isPersonal ? t('moviedb', 'Personal') : lib.name,
				role: lib.role,
			}))
		},
		activeLibraryOption() {
			return this.libraryOptions.find(o => o.id === this.activeLibraryId) || null
		},
	},
	async created() {
		// Fetch libraries first so the active library is known before data loads
		await this.librariesStore.fetchLibraries()
		// Now load data that depends on the active library
		this.watchlistStore.fetchAll()
		this.platformsStore.fetchAll()
		this.settingsStore.fetch()
	},
	methods: {
		onLibraryChange(option) {
			if (!option) return
			this.librariesStore.setActive(option.id)
			// Re-fetch data for the newly active library
			this.watchlistStore.fetchAll()
			this.moviesStore.fetchAll()
			this.seriesStore.fetchAll()
			// Reload current route's data if on dashboard
			if (this.$route.name === 'dashboard') {
				this.$router.go(0)
			}
		},
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

<style lang="scss" scoped>
.library-switcher {
    padding: 8px 12px 12px;
    border-top: 1px solid var(--color-border);
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.readonly-badge {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    padding: 2px 6px;
    background: var(--color-background-darker);
    border-radius: 3px;
    width: fit-content;
}
</style>
