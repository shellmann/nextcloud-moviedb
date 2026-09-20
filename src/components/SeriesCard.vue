<template>
	<div
		class="series-card"
		role="button"
		tabindex="0"
		:aria-labelledby="'series-title-' + series.id"
		@click="$emit('click')"
		@keydown.enter="$emit('click')"
		@keydown.space.prevent="$emit('click')">
		<div class="poster">
			<img
				v-if="series.posterPath"
				:src="posterUrl"
				:alt="series.title"
				loading="lazy">
			<div v-else class="no-poster">
				<Television :size="32" />
			</div>
			<div class="type-badge">
				{{ t('moviedb', 'TV') }}
			</div>
			<div v-if="series.isFavorite" class="favorite-badge">
				<Heart :size="16" />
			</div>
			<div v-if="series.lastRating" class="rating-badge">
				{{ series.lastRating }}
			</div>
		</div>
		<div class="info">
			<h3 :id="'series-title-' + series.id" class="title">
				{{ series.title }}
			</h3>
			<span v-if="series.firstAirYear" class="year">{{ series.firstAirYear }}</span>
			<div v-if="genreLabels.length" class="genre-pills">
				<span v-for="genre in genreLabels" :key="genre" class="genre-pill">{{ genre }}</span>
			</div>
			<div class="meta-row">
				<span v-if="series.lastWatchedAt" class="watched-date">
					<Calendar :size="12" />
					{{ formatDate(series.lastWatchedAt) }}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import Television from 'vue-material-design-icons/Television.vue'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { TV_GENRE_OPTIONS } from '../constants.js'
import { formatDate } from '../utils/formatters.js'

/**
 * SeriesCard component - Displays a TV series in a card format.
 */
export default {
	name: 'SeriesCard',
	components: {
		Television,
		Heart,
		Calendar,
	},

	props: {
		/**
		 * Series object containing all series data
		 *
		 * @type {{ id: number, title: string, posterPath?: string, firstAirYear?: number, genreIds?: (Array|string), lastRating?: number, lastWatchedAt?: string, isFavorite?: boolean }}
		 */
		series: {
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
			return getPosterUrl(this.series.posterPath, 'w300')
		},

		genreLabels() {
			const genreIds = this.series.genreIds
			if (!genreIds) { return [] }
			const ids = Array.isArray(genreIds) ? genreIds : JSON.parse(genreIds || '[]')
			return ids
				.map((id) => TV_GENRE_OPTIONS.find((g) => g.id === id)?.label)
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
@use '../assets/design-tokens' as tokens;

.series-card {
    cursor: pointer;
    border-radius: tokens.$radius-md;
    overflow: hidden;
    background: var(--color-background-dark);
    box-shadow: tokens.$shadow-sm;
    transition: transform tokens.$transition-base, box-shadow tokens.$transition-base;

    &:hover,
    &:focus-visible {
        transform: translateY(-4px);
        box-shadow: tokens.$shadow-md;
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
    background: var(--color-primary);
    color: white;
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
}
</style>
