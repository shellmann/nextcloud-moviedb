<template>
	<!-- The title is the card's one real button (keyboard and screen readers); a click anywhere
		 else on the card does the same for the mouse. The favourite toggle is a separate button
		 next to it, never inside it (no nested interactive controls). -->
	<div class="movie-card" @click="$emit('click')">
		<span
			v-if="languageName"
			:id="'movie-desc-' + movie.id"
			class="hidden-visually">{{ languageName }}</span>
		<div class="poster">
			<img
				v-if="movie.posterPath"
				:src="posterUrl"
				:alt="movie.title"
				loading="lazy">
			<div v-else class="no-poster">
				<Movie :size="32" />
			</div>
			<div class="type-badge">
				{{ t('moviedb', 'Movie') }}
			</div>
			<button
				v-if="canEdit"
				type="button"
				class="favorite-toggle"
				:class="{ active: movie.isFavorite }"
				:aria-pressed="movie.isFavorite ? 'true' : 'false'"
				:aria-label="t('moviedb', 'Favorite: {title}', { title: movie.title })"
				@click.stop="$emit('toggleFavorite')">
				<span class="favorite-circle">
					<Heart v-if="movie.isFavorite" :size="16" />
					<HeartOutline v-else :size="16" />
				</span>
			</button>
			<div v-else-if="movie.isFavorite" class="favorite-badge">
				<Heart :size="16" />
			</div>
			<div v-if="movie.lastRating" class="rating-badge">
				{{ movie.lastRating }}
			</div>
		</div>
		<div class="info">
			<h3 class="title">
				<button
					type="button"
					class="card-link"
					:aria-describedby="languageName ? 'movie-desc-' + movie.id : undefined"
					@click.stop="$emit('click')">
					{{ movie.title }}
				</button>
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
				<span
					v-if="movie.lastLanguage"
					class="language"
					:title="languageName"
					aria-hidden="true">{{ getLanguageFlag(movie.lastLanguage) }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import Movie from 'vue-material-design-icons/Movie.vue'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { GENRE_OPTIONS, getLanguageFlag, getLanguageName } from '../constants.js'
import { formatDate } from '../utils/formatters.js'

/**
 * MovieCard component - Displays a movie in a card format with poster, title, and metadata.
 */
export default {
	name: 'MovieCard',
	components: {
		Movie,
		Heart,
		HeartOutline,
		Calendar,
	},

	props: {
		/**
		 * Movie object containing all movie data
		 *
		 * @type {{ id: number, title: string, posterPath?: string, releaseYear?: number, lastWatchedAt?: string, lastLanguage?: string, lastRating?: number, isFavorite?: boolean }}
		 */
		movie: {
			type: Object,
			required: true,
		},

		/**
		 * Whether the user may edit the library: shows the favourite toggle
		 * instead of the read-only heart badge.
		 */
		canEdit: {
			type: Boolean,
			default: false,
		},
	},

	emits: [
		/**
		 * Emitted when the card is clicked
		 */
		'click',
		/**
		 * Emitted when the favourite heart is clicked (only with canEdit)
		 */
		'toggleFavorite',
	],

	computed: {
		languageName() {
			return getLanguageName(this.movie.lastLanguage)
		},

		posterUrl() {
			return getPosterUrl(this.movie.posterPath, 'w300')
		},

		genreLabels() {
			const genreIds = this.movie.genreIds
			if (!genreIds) { return [] }
			const ids = Array.isArray(genreIds) ? genreIds : JSON.parse(genreIds || '[]')
			return ids
				.map((id) => GENRE_OPTIONS.find((g) => g.id === id)?.label)
				.filter(Boolean)
				.slice(0, 2)
		},
	},

	methods: {
		formatDate,
		getLanguageFlag,
	},
}
</script>

<style lang="scss" scoped>
@use '../assets/design-tokens' as tokens;
@use '../assets/card-controls' as controls;

.hidden-visually {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
}

.movie-card {
    cursor: pointer;
    border-radius: tokens.$radius-md;
    overflow: hidden;
    background: var(--color-background-dark);
    box-shadow: tokens.$shadow-sm;
    transition: transform tokens.$transition-base, box-shadow tokens.$transition-base;

    @include controls.card-focus;
}

.poster {
    position: relative;
    aspect-ratio: 2/3;

    img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    &::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(to bottom, transparent 60%, rgba(0, 0, 0, 0.65) 100%);
        pointer-events: none;
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
    box-shadow: tokens.$shadow-sm;
    z-index: 1;
}

.favorite-toggle {
    @include controls.favorite-toggle;
}

.type-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    background: rgba(0, 0, 0, 0.6);
    color: white;
    border-radius: tokens.$radius-sm;
    padding: 2px 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    z-index: 1;
}

.rating-badge {
    position: absolute;
    bottom: 8px;
    right: 8px;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border-radius: tokens.$radius-sm;
    padding: 2px 8px;
    font-size: 14px;
    font-weight: bold;
    box-shadow: tokens.$shadow-sm;
    z-index: 1;
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

    .card-link {
        @include controls.card-link;
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
        border-radius: tokens.$radius-md;
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
        margin-inline-start: auto;
    }
}
</style>
