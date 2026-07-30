<template>
	<div class="tmdb-search-section">
		<h3>{{ t('moviedb', 'Search TMDB') }}</h3>
		<div class="search-form">
			<NcTextField v-model="searchQuery"
				:label="t('moviedb', 'Movie title')"
				:placeholder="t('moviedb', 'Enter movie title...')"
				@keyup.enter="searchMovies" />
			<NcTextField v-model="searchYear"
				:label="t('moviedb', 'Year (optional)')"
				type="number"
				:placeholder="t('moviedb', 'Year')" />
			<NcButton type="primary"
				:disabled="!searchQuery || searching"
				@click="searchMovies">
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
				<div v-for="movie in searchResults"
					:key="movie.id"
					class="result-item"
					role="button"
					tabindex="0"
					:aria-label="movie.title"
					@click="$emit('select', movie)"
					@keydown.enter="$emit('select', movie)"
					@keydown.space.prevent="$emit('select', movie)">
					<img v-if="movie.poster_path"
						:src="getImageUrl(movie.poster_path)"
						:alt="movie.title">
					<div v-else class="no-poster">
						{{ t('moviedb', 'No poster') }}
					</div>
					<div class="result-info">
						<strong>{{ movie.title }}</strong>
						<span v-if="movie.release_date">{{ movie.release_date.substring(0, 4) }}</span>
					</div>
				</div>
			</div>
		</div>

		<div v-else-if="searched && !searchResults.length" class="no-results">
			<p>{{ t('moviedb', 'No movies found. Try a different search term.') }}</p>
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
 * TmdbSearchSection component - Search interface for finding movies on TMDB.
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
		 * @param {object} movie - The selected TMDB movie object
		 */
		'select',
	],
	setup() {
		const settingsStore = useSettingsStore()
		return { settingsStore }
	},
	data() {
		return {
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
		async searchMovies() {
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
