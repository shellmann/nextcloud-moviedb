<template>
	<div class="movie-card"
		role="button"
		tabindex="0"
		:aria-labelledby="'movie-title-' + movie.id"
		@click="$emit('click')"
		@keydown.enter="$emit('click')"
		@keydown.space.prevent="$emit('click')">
		<div class="poster">
			<img v-if="movie.posterPath"
				:src="posterUrl"
				:alt="movie.title"
				loading="lazy">
			<div v-else class="no-poster">
				<Movie :size="32" />
			</div>
			<div v-if="movie.isFavorite" class="favorite-badge">
				<Heart :size="16" />
			</div>
			<div v-if="movie.lastRating" class="rating-badge">
				{{ movie.lastRating }}
			</div>
		</div>
		<div class="info">
			<h3 :id="'movie-title-' + movie.id" class="title">
				{{ movie.title }}
			</h3>
			<span v-if="movie.releaseYear" class="year">{{ movie.releaseYear }}</span>
			<div v-if="genreLabels.length" class="genre-pills">
				<span v-for="genre in genreLabels" :key="genre" class="genre-pill">{{ genre }}</span>
			</div>
			<div class="meta-row">
				<span v-if="movie.lastWatchedAt" class="watched-date">
					<Calendar :size="12" />
					{{ formatDate(movie.lastWatchedAt) }}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import Movie from 'vue-material-design-icons/Movie.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { GENRE_OPTIONS } from '../constants.js'
import { formatDate } from '../utils/formatters.js'

/**
 * MovieCard component - Displays a movie in a card format with poster, title, and metadata.
 */
export default {
	name: 'MovieCard',
	components: {
		Movie,
		Heart,
		Calendar,
	},
	props: {
		/**
		 * Movie object containing all movie data
		 * @type {{ id: number, title: string, posterPath?: string, releaseYear?: number, dateWatched?: string, languageWatched?: string, rating?: number, isFavorite?: boolean }}
		 */
		movie: {
			type: Object,
			required: true,
		},
	},
	emits: [
		/**
		 * Emitted when the card is clicked
		 */
		'click',
	],
	computed: {
		posterUrl() {
			return getPosterUrl(this.movie.posterPath, 'w300')
		},
		genreLabels() {
			const genreIds = this.movie.genreIds
			if (!genreIds) return []
			const ids = Array.isArray(genreIds) ? genreIds : JSON.parse(genreIds || '[]')
			return ids
				.map(id => GENRE_OPTIONS.find(g => g.id === id)?.label)
				.filter(Boolean)
				.slice(0, 2)
		},
	},
	methods: {
		formatDate,
	},
}
</script>

<style lang="scss" scoped>
.movie-card {
    cursor: pointer;
    border-radius: 8px;
    overflow: hidden;
    background: var(--color-background-dark);
    transition: transform 0.2s, box-shadow 0.2s;

    &:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
    }
}

.poster {
    position: relative;
    aspect-ratio: 2/3;

    img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .no-poster {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--color-background-darker);
        color: var(--color-text-lighter);
    }
}

.favorite-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(231, 76, 60, 0.9);
    color: white;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.rating-badge {
    position: absolute;
    bottom: 8px;
    right: 8px;
    background: var(--color-primary);
    color: white;
    border-radius: 4px;
    padding: 2px 8px;
    font-size: 14px;
    font-weight: bold;
}

.info {
    padding: 12px;

    .title {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .year {
        font-size: 12px;
        color: var(--color-text-lighter);
        display: block;
    }

    .genre-pills {
        display: flex;
        gap: 4px;
        margin-top: 4px;
        flex-wrap: wrap;
    }

    .genre-pill {
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 8px;
        background: var(--color-primary-element-light);
        color: var(--color-primary-element-light-text);
        white-space: nowrap;
    }

    .meta-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 4px;
        gap: 8px;
    }

    .watched-date {
        font-size: 11px;
        color: var(--color-text-lighter);
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .language {
        font-size: 14px;
    }
}
</style>
