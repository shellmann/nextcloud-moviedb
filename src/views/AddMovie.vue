<template>
	<div class="add-movie">
		<div class="page-header">
			<h2>{{ t('moviedb', 'Add Movie') }}</h2>
		</div>

		<div v-if="!hasApiKey" class="api-key-warning">
			<NcNoteCard type="warning">
				<p>
					<strong>{{ t('moviedb', 'TMDB API Key Required') }}</strong><br>
					{{ t('moviedb', 'To search for movies and fetch metadata, you need a free TMDB API key.') }}
					<router-link :to="{ name: 'settings' }">
						{{ t('moviedb', 'Settings') }}
					</router-link>
				</p>
			</NcNoteCard>
		</div>

		<!-- Movie Form (shown when a movie is selected) -->
		<div v-else-if="selectedMovie" class="movie-form-section">
			<div class="form-header">
				<h3>{{ t('moviedb', 'Movie Details') }}</h3>
				<NcButton @click="selectedMovie = null">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('moviedb', 'Back to Search') }}
				</NcButton>
			</div>
			<MovieForm :movie="selectedMovie"
				:platforms="platforms"
				:saving="saving"
				@submit="saveMovie"
				@cancel="selectedMovie = null" />
		</div>

		<!-- Search Section (shown when no movie is selected) -->
		<TmdbSearchSection v-else @select="selectMovie" />
	</div>
</template>

<script>
import { NcNoteCard, NcButton } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import MovieForm from '../components/MovieForm.vue'
import TmdbSearchSection from '../components/TmdbSearchSection.vue'
import api from '../services/api.js'
import { useMoviesStore } from '../stores/movies.js'
import { usePlatformsStore } from '../stores/platforms.js'
import { useSettingsStore } from '../stores/settings.js'

export default {
	name: 'AddMovie',
	components: {
		NcNoteCard,
		NcButton,
		ArrowLeft,
		MovieForm,
		TmdbSearchSection,
	},
	setup() {
		const moviesStore = useMoviesStore()
		const platformsStore = usePlatformsStore()
		const settingsStore = useSettingsStore()
		return { moviesStore, platformsStore, settingsStore }
	},
	data() {
		return {
			selectedMovie: null,
			saving: false,
		}
	},
	computed: {
		hasApiKey() {
			return this.settingsStore.hasApiKey
		},
		platforms() {
			return this.platformsStore.platforms
		},
		tmdbLanguage() {
			return this.settingsStore.defaultLanguage || 'en-US'
		},
	},
	methods: {
		async selectMovie(item, mediaType = 'movie') {
			// Fetch full details from TMDB
			try {
				const isSeries = mediaType === 'series'
				const response = isSeries
					? await api.getTmdbSeriesDetails(item.id, this.tmdbLanguage)
					: await api.getTmdbMovieDetails(item.id, this.tmdbLanguage)
				const details = isSeries ? response.data.series : response.data.movie

				this.selectedMovie = {
					tmdbId: details.id,
					mediaType,
					title: isSeries ? details.name : details.title,
					originalTitle: isSeries ? details.original_name : details.original_title,
					posterPath: details.poster_path,
					backdropPath: details.backdrop_path,
					overview: details.overview,
					genreIds: details.genres?.map(g => g.id) || [],
					releaseDate: isSeries ? details.first_air_date : details.release_date,
					runtime: isSeries ? details.episode_run_time?.[0] ?? null : details.runtime ?? null,
					castData: details.cast,
					director: details.director || '',
					// User input fields
					platformId: null,
					languageWatched: '',
					dateWatched: new Date().toISOString().split('T')[0],
					rating: null,
					review: '',
				}

				// Scroll to top to show the form
				window.scrollTo({ top: 0, behavior: 'smooth' })
			} catch (error) {
				console.error('Failed to fetch details:', error)
				showError(t('moviedb', 'Failed to load details.'))
			}
		},
		async saveMovie(movieData) {
			// Prevent double submission
			if (this.saving) return

			this.saving = true
			const movie = await this.moviesStore.create(movieData)
			if (movie) {
				this.$router.push({ name: 'movies' })
			}
			this.saving = false
		},
	},
}
</script>

<style lang="scss" scoped>
.add-movie {
	padding: 20px;
	max-width: 900px;
	margin: 0 auto;
}

.page-header {
	margin-bottom: 20px;

	h2 {
		margin: 0;
		font-size: 24px;
	}
}

.api-key-warning {
	margin-bottom: 20px;
}

.movie-form-section {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 20px;

	.form-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 16px;

		h3 {
			margin: 0;
		}
	}
}
</style>
