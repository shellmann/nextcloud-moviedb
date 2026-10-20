<template>
	<!-- Not a button itself: it holds buttons, and interactive controls must not be nested.
		 The title is the keyboard and screen reader entry to the show; the poster is a
		 mouse shortcut to the same place. -->
	<article
		class="continue-card"
		:data-series-id="series.id"
		:aria-labelledby="'continue-title-' + series.id">
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
			<h4 :id="'continue-title-' + series.id" class="title">
				<router-link class="title-link" :to="{ name: 'series-detail', params: { id: String(series.id) } }">
					{{ series.title }}
				</router-link>
			</h4>
			<p v-if="next" class="next">
				<!-- One line on purpose: the template compiler drops whitespace between tags across line breaks. -->
				<span class="next-label">{{ started ? t('moviedb', 'Next:') : t('moviedb', 'Start with') }}</span> <strong>{{ episodeCode(next) }}</strong> <span v-if="next.name" class="next-name">{{ next.name }}</span>
			</p>
			<EpisodeProgress :watched="series.watchedEpisodeCount" :aired="series.airedEpisodeCount" />
			<div v-if="canEdit" class="actions">
				<NcButton
					v-if="next"
					class="watched-button"
					variant="primary"
					size="small"
					:disabled="busy"
					:aria-label="t('moviedb', 'Mark {episode} of {title} as watched', { episode: episodeCode(next), title: series.title })"
					@click="markWatched">
					<template #icon>
						<Check :size="20" />
					</template>
					{{ t('moviedb', 'Watched') }}
				</NcButton>
				<NcActions :aria-label="t('moviedb', 'More actions for {title}', { title: series.title })">
					<NcActionButton
						:disabled="busy"
						:aria-label="t('moviedb', 'Stop watching {title}', { title: series.title })"
						@click="stopWatching">
						<template #icon>
							<EyeOff :size="20" />
						</template>
						{{ t('moviedb', 'Stop watching') }}
					</NcActionButton>
				</NcActions>
			</div>
		</div>
	</article>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActions, NcButton } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import Television from 'vue-material-design-icons/Television.vue'
import EpisodeProgress from './EpisodeProgress.vue'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import api from '../services/api.js'
import { useSeriesStore } from '../stores/series.js'
import { episodeCode } from '../utils/formatters.js'

/**
 * A show on the dashboard "continue watching" row: progress, the next episode
 * and quick actions (mark it watched, stop watching the show).
 */
export default {
	name: 'ContinueWatchingCard',
	components: {
		NcActionButton,
		NcActions,
		NcButton,
		Check,
		EyeOff,
		Television,
		EpisodeProgress,
	},

	props: {
		/**
		 * Series row from GET /api/series/continue
		 *
		 * @type {{ id: number, title: string, posterPath?: string, progress: number, watchedEpisodeCount: number, airedEpisodeCount: number, nextEpisode?: object }}
		 */
		series: {
			type: Object,
			required: true,
		},

		/** Whether the user may change the library (marks and stop watching) */
		canEdit: {
			type: Boolean,
			default: false,
		},

		/** Library the row was loaded for */
		libraryId: {
			type: Number,
			default: null,
		},
	},

	emits: [
		/** The poster was clicked: open the show */
		'open',
		/** An episode was marked watched: the row should reload (payload: the series) */
		'changed',
		/** The user stopped watching the show (payload: the series) */
		'stopped',
	],

	setup() {
		const seriesStore = useSeriesStore()
		return { seriesStore }
	},

	data() {
		return {
			busy: false,
		}
	},

	computed: {
		posterUrl() {
			return getPosterUrl(this.series.posterPath, 'w300')
		},

		next() {
			return this.series.nextEpisode || null
		},

		started() {
			return this.series.watchedEpisodeCount > 0
		},
	},

	methods: {
		episodeCode,

		async markWatched() {
			if (this.busy || !this.next) { return }
			this.busy = true
			try {
				await api.markEpisodeWatched(this.series.id, this.next.id, true, this.libraryId ?? undefined)
				this.$emit('changed', this.series)
			} catch (error) {
				console.error('Failed to mark episode watched:', error)
				showError(t('moviedb', 'Failed to mark episode watched. Please try again.'))
			} finally {
				this.busy = false
			}
		},

		async stopWatching() {
			if (this.busy) { return }
			this.busy = true
			const result = await this.seriesStore.setStopped(this.series.id, true, { toast: false })
			this.busy = false
			if (result) {
				// The dashboard reloads and drops this card, so it offers the undo.
				this.$emit('stopped', this.series)
			}
		},
	},
}
</script>

<style lang="scss" scoped>
@use '../assets/design-tokens' as tokens;

.continue-card {
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

    .next {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .next-label {
        color: var(--color-text-maxcontrast);
    }

}

.actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
    margin-top: auto;
    padding-top: 6px;
}

@media (prefers-reduced-motion: reduce) {
    .continue-card {
        transition: none;

        &:hover,
        &:focus-within {
            transform: none;
        }
    }
}
</style>
