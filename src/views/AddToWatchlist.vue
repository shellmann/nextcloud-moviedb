<template>
	<div class="add-to-watchlist">
		<div class="page-header">
			<h2>{{ t('moviedb', 'Add to Watchlist') }}</h2>
		</div>

		<div v-if="!hasApiKey" class="api-key-warning">
			<NcNoteCard type="warning">
				<p>
					<strong>{{ t('moviedb', 'TMDB API Key Required') }}</strong><br>
					{{ t('moviedb', 'Please configure your TMDB API key in') }}
					<router-link :to="{ name: 'settings' }">
						{{ t('moviedb', 'Settings') }}
					</router-link>
					{{ t('moviedb', 'to search for movies.') }}
				</p>
			</NcNoteCard>
		</div>

		<!-- Movie Form (shown when a movie is selected) -->
		<div v-else-if="selectedMovie" class="movie-form-section">
			<div class="form-header">
				<h3>{{ t('moviedb', 'Add to Watchlist') }}</h3>
				<NcButton @click="selectedMovie = null">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('moviedb', 'Back to Search') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="alreadyWatched" type="warning" class="already-watched-note">
				<p>{{ t('moviedb', 'You\'ve already watched this movie. If you add it to your watchlist and mark it as watched later, it will be logged as a rewatch.') }}</p>
			</NcNoteCard>

			<div class="selected-movie">
				<div v-if="selectedMovie.posterPath" class="movie-poster">
					<img :src="getPosterUrl(selectedMovie.posterPath, 'w200')" :alt="selectedMovie.title">
				</div>
				<div class="movie-info">
					<h4>{{ selectedMovie.title }}</h4>
					<p v-if="selectedMovie.releaseDate" class="year">
						{{ selectedMovie.releaseDate.substring(0, 4) }}
					</p>
					<p v-if="selectedMovie.overview" class="overview">
						{{ selectedMovie.overview }}
					</p>

					<div class="form-group">
						<label>{{ t('moviedb', 'Notes (optional)') }}</label>
						<textarea v-model="notes"
							rows="3"
							:placeholder="t('moviedb', 'Why do you want to watch this?')" />
					</div>

					<div class="form-group">
						<label>{{ t('moviedb', 'Priority') }}</label>
						<NcSelect v-model="selectedPriority"
							:options="priorityOptions"
							:placeholder="t('moviedb', 'Select priority')" />
					</div>

					<div class="form-actions">
						<NcButton @click="selectedMovie = null">
							{{ t('moviedb', 'Cancel') }}
						</NcButton>
						<NcButton type="primary" :disabled="saving" @click="addToWatchlist">
							<template #icon>
								<PlaylistPlus :size="20" />
							</template>
							{{ t('moviedb', 'Add to Watchlist') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<!-- Search Section (shown when no movie is selected) -->
		<TmdbSearchSection v-else @select="selectMovie" />
	</div>
</template>

<script>
import { NcNoteCard, NcButton, NcSelect } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import PlaylistPlus from 'vue-material-design-icons/PlaylistPlus.vue'
import TmdbSearchSection from '../components/TmdbSearchSection.vue'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { getPriorityOptions } from '../constants.js'
import { useWatchlistStore } from '../stores/watchlist.js'
import { useSettingsStore } from '../stores/settings.js'
import { useMoviesStore } from '../stores/movies.js'

export default {
	name: 'AddToWatchlist',
	components: {
		NcNoteCard,
		NcButton,
		NcSelect,
		ArrowLeft,
		PlaylistPlus,
		TmdbSearchSection,
	},
	setup() {
		const watchlistStore = useWatchlistStore()
		const settingsStore = useSettingsStore()
		const moviesStore = useMoviesStore()
		return { watchlistStore, settingsStore, moviesStore }
	},
	data() {
		return {
			selectedMovie: null,
			alreadyWatched: false,
			notes: '',
			selectedPriority: null,
			priorityOptions: getPriorityOptions(),
			saving: false,
		}
	},
	computed: {
		hasApiKey() {
			return this.settingsStore.hasApiKey
		},
	},
	methods: {
		getPosterUrl,
		selectMovie(movie) {
			this.selectedMovie = {
				tmdbId: movie.id,
				title: movie.title,
				posterPath: movie.poster_path,
				overview: movie.overview,
				releaseDate: movie.release_date,
				genreIds: movie.genre_ids || [],
			}
			this.notes = ''
			this.selectedPriority = this.priorityOptions[0]
			this.alreadyWatched = this.moviesStore.movies.some(m => m.tmdbId === movie.id)

			// Scroll to top to show the form
			window.scrollTo({ top: 0, behavior: 'smooth' })
		},
		async addToWatchlist() {
			this.saving = true
			const result = await this.watchlistStore.create({
				tmdbId: this.selectedMovie.tmdbId,
				title: this.selectedMovie.title,
				posterPath: this.selectedMovie.posterPath,
				overview: this.selectedMovie.overview,
				releaseDate: this.selectedMovie.releaseDate,
				genreIds: this.selectedMovie.genreIds,
				notes: this.notes,
				priority: this.selectedPriority?.id || 0,
			})
			if (result) {
				this.$router.push({ name: 'watchlist' })
			}
			this.saving = false
		},
	},
}
</script>

<style lang="scss" scoped>
.add-to-watchlist {
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

.already-watched-note {
	margin-bottom: 16px;
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

.selected-movie {
	display: flex;
	gap: 24px;

	@media (max-width: 600px) {
		flex-direction: column;
	}
}

.movie-poster {
	flex-shrink: 0;
	width: 150px;

	img {
		width: 100%;
		border-radius: 8px;
	}
}

.movie-info {
	flex: 1;

	h4 {
		margin: 0 0 4px;
		font-size: 20px;
	}

	.year {
		color: var(--color-text-lighter);
		margin: 0 0 12px;
	}

	.overview {
		margin: 0 0 16px;
		line-height: 1.5;
	}
}

.form-group {
	margin-bottom: 16px;

	label {
		display: block;
		margin-bottom: 4px;
		font-weight: 500;
	}

	textarea {
		width: 100%;
		padding: 8px;
		border: 1px solid var(--color-border);
		border-radius: 4px;
		background: var(--color-main-background);
		color: var(--color-main-text);
		resize: vertical;

		&:focus {
			border-color: var(--color-primary);
			outline: none;
		}
	}
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
