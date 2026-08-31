<template>
	<div class="add-movie">
		<div class="page-header">
			<h2>{{ t('moviedb', 'Add Movie') }}</h2>
		</div>

		<div v-if="!activeCanEdit" class="viewer-notice">
			<NcNoteCard type="info">
				<p>{{ t('moviedb', 'You have view-only access to this library and cannot add items.') }}</p>
			</NcNoteCard>
		</div>

		<div v-else-if="!hasApiKey" class="api-key-warning">
			<NcNoteCard type="warning">
				<p>
					<strong>{{ t('moviedb', 'TMDB API Key Required') }}</strong><br>
					{{ t('moviedb', 'To search for movies and fetch metadata, you need a free TMDB API key.') }}
				</p>
				<NcButton type="primary" @click="$router.push({ name: 'settings' })">
					<template #icon>
						<Cog :size="20" />
					</template>
					{{ t('moviedb', 'Open Settings') }}
				</NcButton>
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

		<!-- Duplicate Movie Dialog -->
		<NcDialog :open="showDuplicateDialog"
			:name="t('moviedb', 'Movie already in your list')"
			@update:open="showDuplicateDialog = $event">
			<p>{{ t('moviedb', 'You have already added this movie to your list. Would you like to view the existing entry?') }}</p>
			<template #actions>
				<NcButton @click="showDuplicateDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="!duplicateExistingId"
					@click="viewExistingMovie">
					{{ t('moviedb', 'View existing entry') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcNoteCard, NcButton, NcDialog } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import MovieForm from '../components/MovieForm.vue'
import TmdbSearchSection from '../components/TmdbSearchSection.vue'
import api from '../services/api.js'
import { useMoviesStore } from '../stores/movies.js'
import { usePlatformsStore } from '../stores/platforms.js'
import { useSettingsStore } from '../stores/settings.js'
import { useLibrariesStore } from '../stores/libraries.js'

export default {
	name: 'AddMovie',
	components: {
		NcNoteCard,
		NcButton,
		NcDialog,
		ArrowLeft,
		Cog,
		MovieForm,
		TmdbSearchSection,
	},
	setup() {
		const moviesStore = useMoviesStore()
		const platformsStore = usePlatformsStore()
		const settingsStore = useSettingsStore()
		const librariesStore = useLibrariesStore()
		return { moviesStore, platformsStore, settingsStore, librariesStore }
	},
	data() {
		return {
			selectedMovie: null,
			saving: false,
			showDuplicateDialog: false,
			duplicateExistingId: null,
		}
	},
	computed: {
		activeCanEdit() {
			return this.librariesStore.activeCanEdit
		},
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
		async selectMovie(movie) {
			// Fetch full details from TMDB
			try {
				const response = await api.getTmdbMovieDetails(movie.id, this.tmdbLanguage)
				const details = response.data.movie

				this.selectedMovie = {
					tmdbId: details.id,
					title: details.title,
					originalTitle: details.original_title,
					posterPath: details.poster_path,
					backdropPath: details.backdrop_path,
					overview: details.overview,
					genreIds: details.genres?.map(g => g.id) || [],
					releaseDate: details.release_date,
					runtime: details.runtime,
					castData: details.cast,
					director: details.director,
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
				console.error('Failed to fetch movie details:', error)
				showError(t('moviedb', 'Failed to load movie details.'))
			}
		},
		async saveMovie(movieData) {
			// Prevent double submission
			if (this.saving) return

			this.saving = true
			const result = await this.moviesStore.create(movieData)
			this.saving = false

			if (result?.duplicate) {
				this.duplicateExistingId = result.existingId
				this.showDuplicateDialog = true
				return
			}
			if (result) {
				this.$router.push({ name: 'movies' })
			}
		},
		viewExistingMovie() {
			if (!this.duplicateExistingId) return
			const id = this.duplicateExistingId
			this.showDuplicateDialog = false
			this.$router.push({ name: 'movie-detail', params: { id: String(id) } })
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
