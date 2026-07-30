<template>
	<div class="movie-card"
		role="button"
		tabindex="0"
		:aria-label="movie.title"
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
			<div v-if="movie.rating" class="rating-badge">
				{{ movie.rating }}
			</div>
		</div>
		<div class="info">
			<h4 class="title">
				{{ movie.title }}
			</h4>
			<span v-if="movie.releaseYear" class="year">{{ movie.releaseYear }}</span>
			<div class="meta-row">
				<span v-if="movie.dateWatched" class="watched-date">
					<Calendar :size="12" />
					{{ formatDate(movie.dateWatched) }}
				</span>
				<span v-if="movie.languageWatched" class="language">
					{{ getLanguageFlag(movie.languageWatched) }}
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
import { getLanguageFlag } from '../constants.js'
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
	},
	methods: {
		formatDate,
		getLanguageFlag,
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
