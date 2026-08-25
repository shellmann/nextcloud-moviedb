<template>
	<div class="movie-detail">
		<div v-if="loading" class="loading">
			<NcLoadingIcon :size="44" />
		</div>

		<template v-else-if="movie">
			<div class="movie-backdrop" :style="backdropStyle" />

			<div class="movie-content">
				<div class="back-link">
					<NcButton @click="$router.push({ name: 'movies' })">
						<template #icon>
							<ArrowLeft :size="20" />
						</template>
						{{ t('moviedb', 'Back to Movies') }}
					</NcButton>
				</div>

				<div class="movie-poster">
					<img v-if="movie.posterPath" :src="posterUrl" :alt="movie.title">
					<div v-else class="no-poster">
						{{ t('moviedb', 'No poster') }}
					</div>
				</div>

				<div class="movie-info">
					<div class="movie-header">
						<div class="movie-titles">
							<h2>{{ movie.title }}</h2>
							<p v-if="movie.originalTitle && movie.originalTitle !== movie.title" class="original-title">
								{{ movie.originalTitle }}
							</p>
						</div>
						<div class="movie-actions">
							<NcButton @click="editMovie">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('moviedb', 'Edit') }}
							</NcButton>
							<NcButton type="error" @click="confirmDelete">
								<template #icon>
									<Delete :size="20" />
								</template>
								{{ t('moviedb', 'Delete') }}
							</NcButton>
						</div>
					</div>

					<div class="movie-meta">
						<span v-if="movie.releaseYear">{{ movie.releaseYear }}</span>
						<span v-if="movie.runtime">{{ formatRuntime(movie.runtime) }}</span>
						<span v-if="movie.director">{{ t('moviedb', 'Director') }}: {{ movie.director }}</span>
					</div>

					<div v-if="latestWatch && latestWatch.rating" class="movie-rating">
						<RatingStars :rating="latestWatch.rating" :max="10" readonly />
						<span>{{ latestWatch.rating }}/10</span>
					</div>

					<p v-if="movie.overview" class="movie-overview">
						{{ movie.overview }}
					</p>

					<div v-if="latestWatch && latestWatch.review" class="movie-review">
						<h3>{{ t('moviedb', 'My Review') }}</h3>
						<p>{{ latestWatch.review }}</p>
					</div>

					<div v-if="movie.castData && movie.castData.length" class="movie-cast">
						<h3>{{ t('moviedb', 'Cast') }}</h3>
						<div class="cast-list">
							<div v-for="actor in movie.castData.slice(0, 6)" :key="actor.name" class="cast-item">
								<strong>{{ actor.name }}</strong>
								<span>{{ actor.character }}</span>
							</div>
						</div>
					</div>

					<!-- Watch history -->
					<div class="watch-history">
						<div class="watch-history-header">
							<h3>{{ t('moviedb', 'Watch history') }}</h3>
							<NcButton @click="showLogDialog = true">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('moviedb', 'Log again') }}
							</NcButton>
						</div>

						<div v-if="watchesStore.loading" class="watches-loading">
							<NcLoadingIcon :size="24" />
						</div>

						<div v-else-if="watchesStore.watches.length === 0" class="watches-empty">
							{{ t('moviedb', 'No watch history yet.') }}
						</div>

						<ul v-else class="watches-list">
							<li v-for="watch in watchesStore.watches" :key="watch.id" class="watch-entry">
								<span class="watch-date">{{ watch.watchedAt ? formatDate(watch.watchedAt) : t('moviedb', 'Unknown date') }}</span>
								<span v-if="watch.rating" class="watch-rating">★ {{ watch.rating }}/10</span>
								<span v-if="getPlatformName(watch.platformId)" class="watch-platform">{{ getPlatformName(watch.platformId) }}</span>
								<span v-if="watch.review" class="watch-review-indicator" :title="watch.review">💬</span>
								<NcActions v-if="watchesStore.watches.length > 1">
									<NcActionButton @click="deleteWatch(watch.id)">
										<template #icon>
											<Delete :size="20" />
										</template>
										{{ t('moviedb', 'Delete') }}
									</NcActionButton>
								</NcActions>
							</li>
						</ul>
					</div>
				</div>
			</div>
		</template>

		<NcEmptyContent v-else :name="t('moviedb', 'Movie not found')">
			<template #icon>
				<Movie :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Delete Confirmation Dialog -->
		<NcDialog :open="showDeleteDialog"
			:name="t('moviedb', 'Delete Movie')"
			@update:open="showDeleteDialog = $event">
			<p>{{ t('moviedb', 'Are you sure you want to delete this movie?') }}</p>
			<template #actions>
				<NcButton @click="showDeleteDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="deleteMovie">
					{{ t('moviedb', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Log watch dialog -->
		<NcDialog :open="showLogDialog"
			:name="t('moviedb', 'Log watch')"
			@update:open="showLogDialog = $event">
			<div class="log-watch-form">
				<label>{{ t('moviedb', 'Date watched') }}
					<input v-model="logForm.watchedAt" type="date" class="log-input">
				</label>
				<div class="log-rating-row">
					<label>{{ t('moviedb', 'Rating') }}</label>
					<div class="log-rating-control">
						<RatingStars :rating="logForm.rating || 0" :max="10" @update="logForm.rating = $event" />
						<span v-if="logForm.rating" class="log-rating-value">{{ logForm.rating }}/10</span>
						<button v-if="logForm.rating" class="log-rating-clear" @click="logForm.rating = null">
							×
						</button>
					</div>
				</div>
				<label>{{ t('moviedb', 'Review') }}
					<textarea v-model="logForm.review" class="log-input" rows="3" />
				</label>
			</div>
			<template #actions>
				<NcButton @click="showLogDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" @click="submitLog">
					{{ t('moviedb', 'Save') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcDialog, NcActions, NcActionButton } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Movie from 'vue-material-design-icons/Movie.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import RatingStars from '../components/RatingStars.vue'
import { getLanguageName } from '../constants.js'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { formatDate, formatRuntime } from '../utils/formatters.js'
import { useMoviesStore } from '../stores/movies.js'
import { usePlatformsStore } from '../stores/platforms.js'
import { useWatchesStore } from '../stores/watches.js'

export default {
	name: 'MovieDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcDialog,
		NcActions,
		NcActionButton,
		Pencil,
		Delete,
		ArrowLeft,
		Movie,
		Plus,
		RatingStars,
	},
	props: {
		id: {
			type: [String, Number],
			required: true,
		},
	},
	setup() {
		const moviesStore = useMoviesStore()
		const platformsStore = usePlatformsStore()
		const watchesStore = useWatchesStore()
		return { moviesStore, platformsStore, watchesStore }
	},
	data() {
		return {
			showDeleteDialog: false,
			showLogDialog: false,
			logForm: {
				watchedAt: new Date().toISOString().slice(0, 10),
				rating: null,
				review: '',
			},
		}
	},
	computed: {
		movie() {
			return this.moviesStore.currentMovie
		},
		loading() {
			return this.moviesStore.loading
		},
		platforms() {
			return this.platformsStore.platforms
		},
		latestWatch() {
			// Store returns watches sorted by watched_at DESC, so the first is latest.
			return this.watchesStore.watches[0] ?? null
		},
		posterUrl() {
			return getPosterUrl(this.movie?.posterPath, 'w500')
		},
		backdropStyle() {
			if (!this.movie?.backdropPath) return {}
			const url = getPosterUrl(this.movie.backdropPath, 'w1280')
			return {
				backgroundImage: `linear-gradient(to bottom, rgba(0,0,0,0.7), var(--color-main-background)), url(${url})`,
			}
		},
	},
	created() {
		this.loadMovie()
	},
	methods: {
		async loadMovie() {
			await this.moviesStore.fetchOne(this.id)
			await this.watchesStore.fetchForMovie(this.id)
		},
		formatRuntime,
		formatDate,
		getLanguageName,
		getPlatformName(platformId) {
			if (!platformId) return null
			return this.platforms.find(p => p.id === platformId)?.name ?? null
		},
		editMovie() {
			this.$router.push({ name: 'edit-movie', params: { id: this.id } })
		},
		confirmDelete() {
			this.showDeleteDialog = true
		},
		async deleteMovie() {
			const success = await this.moviesStore.delete(this.id)
			if (success) {
				this.$router.push({ name: 'movies' })
			}
			this.showDeleteDialog = false
		},
		async submitLog() {
			const data = {
				watchedAt: this.logForm.watchedAt || null,
				rating: this.logForm.rating || null,
				review: this.logForm.review || null,
			}
			await this.watchesStore.create(this.id, data)
			this.showLogDialog = false
			this.logForm = { watchedAt: new Date().toISOString().slice(0, 10), rating: null, review: '' }
		},
		async deleteWatch(watchId) {
			await this.watchesStore.delete(this.id, watchId)
		},
	},
}
</script>

<style lang="scss" scoped>
.movie-detail {
    min-height: 100%;
}

.loading {
    display: flex;
    justify-content: center;
    padding: 40px;
}

.movie-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 300px;
    background-size: cover;
    background-position: center;
    z-index: 0;
}

.movie-content {
    position: relative;
    z-index: 1;
    display: flex;
    gap: 32px;
    padding: 20px;
    padding-top: 100px;
    max-width: 1000px;
    margin: 0 auto;
    flex-wrap: wrap;

    @media (max-width: 768px) {
        flex-direction: column;
        align-items: center;
        padding-top: 40px;
    }
}

.back-link {
    width: 100%;
    margin-bottom: 8px;
}

.movie-poster {
    flex-shrink: 0;
    width: 250px;

    img {
        width: 100%;
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    }

    .no-poster {
        width: 100%;
        aspect-ratio: 2/3;
        background: var(--color-background-dark);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--color-text-lighter);
    }
}

.movie-info {
    flex: 1;
}

.movie-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 12px;

    .movie-titles {
        flex: 1;

        h2 {
            margin: 0;
            font-size: 28px;
        }

        .original-title {
            margin: 4px 0 0;
            font-size: 16px;
            color: var(--color-text-lighter);
            font-style: italic;
        }
    }

    .movie-actions {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
    }

    @media (max-width: 600px) {
        flex-direction: column;

        .movie-titles h2 {
            font-size: 22px;
        }
    }
}

.movie-meta {
    display: flex;
    gap: 16px;
    color: var(--color-text-lighter);
    margin-bottom: 16px;
}

.movie-rating {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;

    span {
        font-size: 18px;
        font-weight: bold;
    }
}

.movie-overview {
    line-height: 1.6;
    margin-bottom: 16px;
}

.movie-review {
    background: var(--color-background-dark);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;

    h3 {
        margin: 0 0 8px;
        font-size: 1.1em;
    }

    p {
        margin: 0;
        white-space: pre-wrap;
    }
}

.movie-cast {
    h3 {
        margin: 0 0 12px;
        font-size: 1.1em;
    }
}

.cast-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
}

.cast-item {
    strong {
        display: block;
        font-size: 14px;
    }

    span {
        font-size: 12px;
        color: var(--color-text-lighter);
    }
}

.watch-history {
    margin-top: 24px;

    &-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;

        h3 {
            margin: 0;
            font-size: 1.1em;
        }
    }
}

.watches-loading,
.watches-empty {
    padding: 12px 0;
    color: var(--color-text-lighter);
}

.watches-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.watch-entry {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    background: var(--color-background-dark);
    border-radius: 6px;
    font-size: 14px;

    .watch-date {
        font-weight: 500;
        flex-shrink: 0;
    }

    .watch-rating {
        color: var(--color-text-maxcontrast);
    }

    .watch-platform,
    .watch-review-indicator {
        color: var(--color-text-maxcontrast);
    }

    > :last-child {
        margin-left: auto;
    }
}

.log-watch-form {
    display: flex;
    flex-direction: column;
    gap: 12px;

    label {
        display: flex;
        flex-direction: column;
        gap: 4px;
        font-size: 14px;
    }

    .log-rating-row {
        display: flex;
        flex-direction: column;
        gap: 4px;
        font-size: 14px;
    }

    .log-rating-control {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .log-rating-value {
        font-size: 13px;
        color: var(--color-text-maxcontrast);
    }

    .log-rating-clear {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--color-text-lighter);
        font-size: 16px;
        padding: 0 4px;
        line-height: 1;

        &:hover {
            color: var(--color-main-text);
        }
    }

    .log-input {
        border: 1px solid var(--color-border);
        border-radius: 4px;
        padding: 6px 8px;
        background: var(--color-main-background);
        color: var(--color-main-text);
        font-size: 14px;
    }
}
</style>
