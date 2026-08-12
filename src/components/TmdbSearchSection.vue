<template>
	<div class="tmdb-search-section">
		<h3>{{ t('moviedb', 'Search TMDB') }}</h3>
		<div class="media-type-toggle" role="group" aria-label="Media type">
			<NcButton :type="mediaType === 'movie' ? 'primary' : 'secondary'"
				:aria-pressed="mediaType === 'movie'"
				@click="switchMediaType('movie')">
				{{ t('moviedb', 'Movies') }}
			</NcButton>
			<NcButton :type="mediaType === 'series' ? 'primary' : 'secondary'"
				:aria-pressed="mediaType === 'series'"
				@click="switchMediaType('series')">
				{{ t('moviedb', 'TV Shows') }}
			</NcButton>
		</div>
		<div class="search-form">
			<NcTextField v-model="searchQuery"
				:label="mediaType === 'series' ? t('moviedb', 'Series title') : t('moviedb', 'Movie title')"
				:placeholder="mediaType === 'series' ? t('moviedb', 'Enter series title...') : t('moviedb', 'Enter movie title...')"
				@keyup.enter="searchMedia" />
			<NcTextField v-model="searchYear"
				:label="t('moviedb', 'Year (optional)')"
				type="number"
				:placeholder="t('moviedb', 'Year')" />
			<NcButton type="primary"
				:disabled="!searchQuery || searching"
				@click="searchMedia">
				<template #icon>
					<Magnify :size="20" />
				</template>
				{{ t('moviedb', 'Search') }}
			</NcButton>
		</div>

		<div v-if="searching" class="loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="searchResults.length" class="search-results">
			<h4>{{ t('moviedb', 'Search Results - Click to select') }}</h4>
			<div class="results-grid">
				<div v-for="item in searchResults"
					:key="item.id"
					class="result-item"
					role="button"
					tabindex="0"
					:aria-label="getResultTitle(item)"
					@click="selectItem(item)"
					@keydown.enter="selectItem(item)"
					@keydown.space.prevent="selectItem(item)">
					<img v-if="item.poster_path"
						:src="getImageUrl(item.poster_path)"
						:alt="getResultTitle(item)">
					<div v-else class="no-poster">
						{{ t('moviedb', 'No poster') }}
					</div>
					<div class="result-info">
						<strong>{{ getResultTitle(item) }}</strong>
						<span v-if="getResultYear(item)">{{ getResultYear(item) }}</span>
					</div>
				</div>
			</div>
		</div>

		<div v-else-if="searched && !searchResults.length" class="no-results">
			<p>{{ t('moviedb', 'No results found. Try a different search term.') }}</p>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import api from '../services/api.js'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { useSettingsStore } from '../stores/settings.js'

/**
 * TmdbSearchSection component - Search interface for finding movies or TV shows on TMDB.
 * Allows users to search by title and optional year filter.
 */
export default {
	name: 'TmdbSearchSection',
	components: {
		NcTextField,
		NcButton,
		NcLoadingIcon,
		Magnify,
	},
	emits: [
		/**
		 * Emitted when a search result is selected
		 * @param {object} item - The selected TMDB movie or series object
		 * @param {string} mediaType - 'movie' or 'series'
		 */
		'select',
	],
	setup() {
		const settingsStore = useSettingsStore()
		return { settingsStore }
	},
	data() {
		return {
			mediaType: 'movie',
			searchQuery: '',
			searchYear: '',
			searchResults: [],
			searching: false,
			searched: false,
		}
	},
	computed: {
		tmdbLanguage() {
			return this.settingsStore.defaultLanguage || 'en-US'
		},
	},
	mounted() {
		// Auto-focus the search field
		this.$nextTick(() => {
			const input = this.$el?.querySelector('input[type="text"]')
			if (input) input.focus()
		})
	},
	methods: {
		getImageUrl(path) {
			return getPosterUrl(path, 'w200')
		},
		getResultTitle(item) {
			return this.mediaType === 'series' ? item.name : item.title
		},
		getResultYear(item) {
			const date = this.mediaType === 'series' ? item.first_air_date : item.release_date
			return date?.substring(0, 4) || null
		},
		switchMediaType(type) {
			if (this.mediaType === type) return
			this.mediaType = type
			this.searchResults = []
			this.searched = false
			this.searchQuery = ''
		},
		selectItem(item) {
			this.$emit('select', item, this.mediaType)
		},
		async searchMedia() {
			if (!this.searchQuery) return

			this.searching = true
			this.searched = false
			this.searchResults = []

			try {
				const response = await api.searchTmdb(
					this.searchQuery,
					this.searchYear || null,
					1,
					this.tmdbLanguage,
					this.mediaType,
				)
				this.searchResults = response.data.results || []
				this.searched = true
			} catch (error) {
				console.error('Search failed:', error)
				showError(t('moviedb', 'Search failed. Please try again.'))
			} finally {
				this.searching = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.tmdb-search-section {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 20px;
	margin-bottom: 24px;

	h3 {
		margin: 0 0 16px;
	}
}

.media-type-toggle {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.search-form {
	display: flex;
	gap: 12px;
	align-items: flex-end;
	flex-wrap: wrap;
}

.loading {
	display: flex;
	justify-content: center;
	padding: 20px;
}

.search-results {
	margin-top: 20px;

	h4 {
		margin: 0 0 12px;
	}
}

.results-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 16px;
}

.result-item {
	cursor: pointer;
	border-radius: 8px;
	overflow: hidden;
	background: var(--color-background-darker);
	transition: transform 0.2s;

	&:hover {
		transform: scale(1.03);
	}

	img {
		width: 100%;
		aspect-ratio: 2/3;
		object-fit: cover;
	}

	.no-poster {
		width: 100%;
		aspect-ratio: 2/3;
		display: flex;
		align-items: center;
		justify-content: center;
		background: var(--color-background-darker);
		color: var(--color-text-lighter);
		font-size: 12px;
	}

	.result-info {
		padding: 8px;

		strong {
			display: block;
			font-size: 13px;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		span {
			font-size: 12px;
			color: var(--color-text-lighter);
		}
	}
}

.no-results {
	text-align: center;
	padding: 20px;
	color: var(--color-text-lighter);
}
</style>
