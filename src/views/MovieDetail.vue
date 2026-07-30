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

					<div v-if="movie.rating" class="movie-rating">
						<RatingStars :rating="movie.rating" :max="10" readonly />
						<span>{{ movie.rating }}/10</span>
					</div>

					<div class="watch-info">
						<div v-if="platformName" class="info-item">
							<strong>{{ t('moviedb', 'Watched on') }}:</strong> {{ platformName }}
						</div>
						<div v-if="movie.dateWatched" class="info-item">
							<strong>{{ t('moviedb', 'Date') }}:</strong> {{ formatDate(movie.dateWatched) }}
						</div>
						<div v-if="movie.languageWatched" class="info-item">
							<strong>{{ t('moviedb', 'Language') }}:</strong> {{ getLanguageName(movie.languageWatched) }}
						</div>
					</div>

					<p v-if="movie.overview" class="movie-overview">
						{{ movie.overview }}
					</p>

					<div v-if="movie.review" class="movie-review">
						<h4>{{ t('moviedb', 'My Review') }}</h4>
						<p>{{ movie.review }}</p>
					</div>

					<div v-if="movie.castData && movie.castData.length" class="movie-cast">
						<h4>{{ t('moviedb', 'Cast') }}</h4>
						<div class="cast-list">
							<div v-for="actor in movie.castData.slice(0, 6)" :key="actor.name" class="cast-item">
								<strong>{{ actor.name }}</strong>
								<span>{{ actor.character }}</span>
							</div>
						</div>
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
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcDialog } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Movie from 'vue-material-design-icons/Movie.vue'
import RatingStars from '../components/RatingStars.vue'
import { getLanguageName } from '../constants.js'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { formatDate, formatRuntime } from '../utils/formatters.js'
import { useMoviesStore } from '../stores/movies.js'
import { usePlatformsStore } from '../stores/platforms.js'

export default {
	name: 'MovieDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcDialog,
		Pencil,
		Delete,
		ArrowLeft,
		Movie,
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
		return { moviesStore, platformsStore }
	},
	data() {
		return {
			showDeleteDialog: false,
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
		platformName() {
			if (!this.movie?.platformId) return null
			const platform = this.platforms.find(p => p.id === this.movie.platformId)
			return platform?.name
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
		},
		formatRuntime,
		formatDate,
		getLanguageName,
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

.watch-info {
    background: var(--color-background-dark);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;

    .info-item {
        margin-bottom: 8px;

        &:last-child {
            margin-bottom: 0;
        }
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

    h4 {
        margin: 0 0 8px;
    }

    p {
        margin: 0;
        white-space: pre-wrap;
    }
}

.movie-cast {
    h4 {
        margin: 0 0 12px;
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
</style>
