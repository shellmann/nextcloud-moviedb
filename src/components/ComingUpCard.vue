<template>
	<!-- Nothing to do with an episode that has not aired, so no buttons: the title is the
		 keyboard and screen reader entry to the show, the poster is a mouse shortcut. -->
	<article
		class="coming-card"
		:data-series-id="series.id"
		:aria-labelledby="'coming-title-' + series.id">
		<div class="poster" aria-hidden="true" @click="$emit('open')">
			<img
				v-if="series.posterPath"
				:src="posterUrl"
				alt=""
				loading="lazy">
			<div v-else class="no-poster">
				<Television :size="32" />
			</div>
		</div>
		<div class="info">
			<h4 :id="'coming-title-' + series.id" class="title">
				<router-link class="title-link" :to="{ name: 'series-detail', params: { id: String(series.id) } }">
					{{ series.title }}
				</router-link>
			</h4>
			<p class="air-date">
				<!-- One line on purpose (whitespace between tags); each part stays together so "21 Oct" never breaks apart. -->
				<time :datetime="episode.airDate"><span v-if="relativeText" class="nowrap">{{ relativeText }} ·</span> <span class="nowrap">{{ dateLabel }}</span></time>
			</p>
			<p class="episode">
				<!-- The code is short for the eye; screen readers get it spelled out. -->
				<!-- One line on purpose: the template compiler drops whitespace between tags across line breaks. -->
				<span aria-hidden="true"><strong>{{ codeText }}</strong> <span v-if="showName">{{ episode.name }}</span></span>
				<span class="hidden-visually">{{ spokenText }}</span>
			</p>
			<p v-if="badge" class="badge">
				{{ badge }}
			</p>
		</div>
	</article>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Television from 'vue-material-design-icons/Television.vue'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { episodeCode, formatDate, relativeAirDate } from '../utils/formatters.js'

/**
 * A show on the dashboard "coming up" row: when its next episode airs, which
 * episode it is and whether it starts a season. Several episodes on one day (a
 * whole season at once) read as a range.
 */
export default {
	name: 'ComingUpCard',
	components: {
		Television,
	},

	props: {
		/**
		 * Series row from GET /api/series/upcoming
		 *
		 * @type {{ id: number, title: string, posterPath?: string, upcomingEpisode: object, sameDayCount: number }}
		 */
		series: {
			type: Object,
			required: true,
		},
	},

	emits: [
		/** The poster was clicked: open the show */
		'open',
	],

	computed: {
		posterUrl() {
			return getPosterUrl(this.series.posterPath, 'w300')
		},

		episode() {
			return this.series.upcomingEpisode
		},

		count() {
			return Math.max(1, this.series.sameDayCount || 1)
		},

		/** One episode has a name worth showing; a whole season released at once does not (the first title would read as the title of all). */
		showName() {
			return this.count === 1 && Boolean(this.episode.name)
		},

		lastNumber() {
			return this.episode.episodeNumber + this.count - 1
		},

		dateLabel() {
			return formatDate(this.episode.airDate, { weekday: 'short', day: 'numeric', month: 'short' })
		},

		/** "Tomorrow", "In 3 days"; empty when the date is far off or past. */
		relativeText() {
			return relativeAirDate(this.episode.airDate) || ''
		},

		codeText() {
			if (this.count === 1) {
				return episodeCode(this.episode)
			}
			return `${episodeCode(this.episode)}–E${String(this.lastNumber).padStart(2, '0')}`
		},

		spokenText() {
			const season = this.episode.seasonNumber
			const spoken = this.count === 1
				? t('moviedb', 'Season {season}, episode {number}', { season, number: this.episode.episodeNumber })
				: t('moviedb', 'Season {season}, episodes {from} to {to}', { season, from: this.episode.episodeNumber, to: this.lastNumber })
			return this.showName ? `${spoken}: ${this.episode.name}` : spoken
		},

		/** A text badge, not a colour: starts a new season or the whole show. */
		badge() {
			if (this.episode.episodeNumber !== 1) {
				return ''
			}
			return this.episode.seasonNumber === 1 ? t('moviedb', 'Premiere') : t('moviedb', 'New season')
		},
	},

	methods: {
		t,
	},
}
</script>

<style lang="scss" scoped>
@use '../assets/design-tokens' as tokens;

.coming-card {
    display: flex;
    flex-direction: column;
    border-radius: tokens.$radius-md;
    overflow: hidden;
    background: var(--color-background-dark);
    box-shadow: tokens.$shadow-sm;
    transition: transform tokens.$transition-base, box-shadow tokens.$transition-base;

    &:hover,
    &:focus-within {
        transform: translateY(-4px);
        box-shadow: tokens.$shadow-md;
    }
}

.poster {
    position: relative;
    aspect-ratio: 2/3;
    cursor: pointer;

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

.info {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 4px;
    padding: 10px 12px 12px;

    .title {
        // Room for the focus outline, which overflow: hidden would clip otherwise.
        margin: -2px;
        padding: 2px;
        font-size: 14px;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .title-link {
        color: inherit;

        &:hover {
            text-decoration: underline;
        }

        &:focus-visible {
            outline: 2px solid var(--color-main-text);
            outline-offset: 0;
            border-radius: 2px;
        }
    }

    p {
        margin: 0;
        font-size: 12px;
    }

    // The date is the point of this card: normal text colour, not the muted grey.
    .air-date {
        font-weight: 600;
    }

    .nowrap {
        white-space: nowrap;
    }

    .episode {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .badge {
        align-self: flex-start;
        margin-top: auto;
        padding: 2px 8px;
        border-radius: tokens.$radius-md;
        border: 1px solid var(--color-primary-element);
        color: var(--color-primary-element-text, var(--color-main-text));
        background: var(--color-primary-element);
        font-weight: 600;
    }
}

.hidden-visually {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

@media (prefers-reduced-motion: reduce) {
    .coming-card {
        transition: none;

        &:hover,
        &:focus-within {
            transform: none;
        }
    }
}
</style>
