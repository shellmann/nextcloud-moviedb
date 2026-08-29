<template>
	<div class="tmdb-search-section">
		<h3>{{ t('moviedb', 'Search TMDB') }}</h3>

		<div v-if="allowTypeToggle" class="media-type-toggle">
			<NcCheckboxRadioSwitch :model-value="mediaType"
				value="movie"
				name="media-type"
				type="radio"
				button-variant
				button-variant-grouped="horizontal"
				@update:model-value="switchMediaType">
				{{ t('moviedb', 'Movies') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch :model-value="mediaType"
				value="series"
				name="media-type"
				type="radio"
				button-variant
				button-variant-grouped="horizontal"
				@update:model-value="switchMediaType">
				{{ t('moviedb', 'TV Shows') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div class="search-form">
			<NcTextField v-model="searchQuery"
				:label="searchLabel"
				:placeholder="searchPlaceholder"
				@keyup.enter="search" />
			<NcTextField v-model="searchYear"
				:label="t('moviedb', 'Year (optional)')"
				type="number"
				:placeholder="t('moviedb', 'Year')" />
			<NcButton type="primary"
				:disabled="!searchQuery || searching"
				@click="search">
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
					@click="$emit('select', item, mediaType)"
					@keydown.enter="$emit('select', item, mediaType)"
					@keydown.space.prevent="$emit('select', item, mediaType)">
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
			<p v-if="mediaType === 'series'">
				{{ t('moviedb', 'No TV shows found. Try a different search term.') }}
			</p>
			<p v-else>
				{{ t('moviedb', 'No movies found. Try a different search term.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import api from '../services/api.js'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { useSettingsStore } from '../stores/settings.js'
import { MEDIA_TYPE } from '../constants.js'

/**
 * TmdbSearchSection component - Search interface for finding movies or TV shows on TMDB.
 * Emits `select(item, mediaType)`; defaults to movie so existing movie flows are unchanged.
 */
export default {
	name: 'TmdbSearchSection',
	components: {
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		Magnify,
	},
	props: {
		/**
		 * Whether to show the Movies/TV toggle. Off by default so AddMovie /
		 * AddToWatchlist keep a movie-only search.
		 */
		allowTypeToggle: {
			type: Boolean,
			default: false,
		},
		/**
		 * Initial media type, one of MEDIA_TYPE values.
		 */
		initialMediaType: {
			type: String,
			default: MEDIA_TYPE.MOVIE,
		},
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
			mediaType: this.initialMediaType,
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
		searchLabel() {
			return this.mediaType === MEDIA_TYPE.SERIES
				? t('moviedb', 'TV show title')
				: t('moviedb', 'Movie title')
		},
		searchPlaceholder() {
			return this.mediaType === MEDIA_TYPE.SERIES
				? t('moviedb', 'Enter TV show title...')
				: t('moviedb', 'Enter movie title...')
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
			// Movies use `title`, TV shows use `name`.
			return item.title || item.name || ''
		},
		getResultYear(item) {
			const date = item.release_date || item.first_air_date
			return date ? date.substring(0, 4) : ''
		},
		switchMediaType(value) {
			if (value === this.mediaType) return
			this.mediaType = value
			this.searchResults = []
			this.searched = false
		},
		async search() {
			if (!this.searchQuery) return

			this.searching = true
			this.searched = false
			this.searchResults = []

			try {
				const response = this.mediaType === MEDIA_TYPE.SERIES
					? await api.searchTmdbSeries(this.searchQuery, this.searchYear || null, 1, this.tmdbLanguage)
					: await api.searchTmdb(this.searchQuery, this.searchYear || null, 1, this.tmdbLanguage)
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
	gap: 0;
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
