<template>
	<div class="series-detail">
		<div v-if="loading && !series" class="loading">
			<NcLoadingIcon :size="44" />
		</div>

		<template v-else-if="series">
			<div class="series-backdrop" :style="backdropStyle" />

			<div class="series-content">
				<div class="back-link">
					<NcButton @click="$router.push({ name: 'series' })">
						<template #icon>
							<ArrowLeft :size="20" />
						</template>
						{{ t('moviedb', 'Back to TV Shows') }}
					</NcButton>
				</div>

				<div class="series-poster">
					<img v-if="series.posterPath" :src="posterUrl" :alt="series.title">
					<div v-else class="no-poster">
						{{ t('moviedb', 'No poster') }}
					</div>
				</div>

				<div class="series-info">
					<div class="series-header">
						<div class="series-titles">
							<h2>{{ series.title }}</h2>
							<p v-if="series.originalTitle && series.originalTitle !== series.title" class="original-title">
								{{ series.originalTitle }}
							</p>
						</div>
						<div class="series-actions">
							<!-- aria-disabled instead of disabled: a disabled button drops keyboard focus mid-update. -->
							<NcButton
								v-if="activeCanEdit && series.tmdbId"
								class="refresh-button"
								:aria-busy="refreshing ? 'true' : 'false'"
								:aria-disabled="refreshing ? 'true' : null"
								@click="refreshFromTmdb">
								<template #icon>
									<NcLoadingIcon v-if="refreshing" :size="20" />
									<Refresh v-else :size="20" />
								</template>
								{{ t('moviedb', 'Update from TMDB') }}
							</NcButton>
							<NcButton v-if="activeCanEdit" @click="editSeries">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('moviedb', 'Edit') }}
							</NcButton>
							<NcButton
								v-if="activeCanEdit"
								variant="error"
								@click="showDeleteDialog = true">
								<template #icon>
									<Delete :size="20" />
								</template>
								{{ t('moviedb', 'Delete') }}
							</NcButton>
						</div>
					</div>

					<div class="series-meta">
						<span v-if="series.firstAirYear">{{ series.firstAirYear }}</span>
						<span v-if="series.status">{{ series.status }}</span>
						<span v-if="series.director">{{ t('moviedb', 'Creator') }}: {{ series.director }}</span>
					</div>
					<p v-if="series.tmdbId && refreshedOn" class="refreshed-note">
						{{ t('moviedb', 'Episodes last updated from TMDB: {date}', { date: refreshedOn }) }}
					</p>

					<!-- Series-level watch metadata (the show owns rating/platform/
						 language/date, just like a movie). -->
					<div v-if="hasWatchMeta" class="series-watch-summary">
						<span v-if="series.rating" class="watch-meta-rating">
							★ {{ series.rating }}/10
						</span>
						<span v-if="series.platformName">
							{{ t('moviedb', 'Watched on') }}: {{ series.platformName }}
						</span>
						<span v-if="series.languageWatched">
							{{ t('moviedb', 'Language') }}: <span aria-hidden="true">{{ getLanguageFlag(series.languageWatched) }}</span> {{ languageLabel(series.languageWatched) }}
						</span>
						<span v-if="series.watchedAt">
							{{ t('moviedb', 'Date watched') }}: {{ formatDate(series.watchedAt) }}
						</span>
					</div>

					<!-- Overall progress -->
					<div class="progress-block">
						<!-- Nothing to track yet: no progress, nothing to mark or stop. -->
						<p v-if="!hasAiredEpisodes" class="no-episodes">
							{{ hasEpisodes ? t('moviedb', 'No episodes have aired yet.') : t('moviedb', 'No episodes are known for this show yet.') }}
						</p>
						<div v-if="hasAiredEpisodes" class="progress-label">
							<span>{{ t('moviedb', 'Progress') }}</span>
							<span class="progress-value">
								{{ series.watchedEpisodeCount }} / {{ series.airedEpisodeCount }}
								({{ series.progress }}%)
							</span>
						</div>
						<NcProgressBar v-if="hasAiredEpisodes" :value="series.progress" size="medium" />
						<div v-if="activeCanEdit && (hasAiredEpisodes || stopped)" class="progress-actions">
							<NcButton
								v-if="hasAiredEpisodes"
								:disabled="series.caughtUp || marking"
								@click="markSeriesWatched(true)">
								<template #icon>
									<CheckAll :size="20" />
								</template>
								{{ series.caughtUp ? t('moviedb', 'Caught up') : t('moviedb', 'Mark series watched') }}
							</NcButton>
							<NcButton
								v-if="series.watchedEpisodeCount > 0"
								:disabled="marking"
								@click="markSeriesWatched(false)">
								{{ t('moviedb', 'Mark series unwatched') }}
							</NcButton>
							<NcButton
								:disabled="marking"
								@click="setStopped(!stopped)">
								<template #icon>
									<PlayCircleOutline v-if="stopped" :size="20" />
									<EyeOff v-else :size="20" />
								</template>
								{{ stopped ? t('moviedb', 'Resume watching') : t('moviedb', 'Stop watching') }}
							</NcButton>
						</div>
						<p v-if="stopped" class="stopped-note">
							<EyeOff :size="16" />
							{{ t('moviedb', 'You stopped watching this show. It no longer appears in "Continue watching".') }}
						</p>
					</div>

					<!-- Next episode -->
					<div v-if="series.nextEpisode" class="next-episode">
						<h3>{{ t('moviedb', 'Up next') }}</h3>
						<div class="next-episode-card">
							<div class="next-episode-info">
								<strong>{{ episodeCode(series.nextEpisode) }}</strong>
								<span>{{ series.nextEpisode.name }}</span>
								<span v-if="series.nextEpisode.airDate" class="next-episode-date">{{ formatDate(series.nextEpisode.airDate) }}</span>
							</div>
							<NcButton
								v-if="activeCanEdit"
								variant="primary"
								:disabled="marking"
								@click="toggleEpisode(series.nextEpisode, true)">
								<template #icon>
									<Check :size="20" />
								</template>
								{{ t('moviedb', 'Mark watched') }}
							</NcButton>
						</div>
					</div>

					<p v-if="series.overview" class="series-overview">
						{{ series.overview }}
					</p>

					<!-- Seasons -->
					<div class="seasons">
						<div
							v-for="season in series.seasons"
							:key="season.seasonNumber"
							class="season">
							<div class="season-header">
								<h3>
									<button
										type="button"
										class="season-toggle"
										:aria-expanded="expandedSeasons.includes(season.seasonNumber) ? 'true' : 'false'"
										:aria-controls="'season-episodes-' + season.seasonNumber"
										@click="toggleSeason(season.seasonNumber)">
										<ChevronDown v-if="expandedSeasons.includes(season.seasonNumber)" :size="20" aria-hidden="true" />
										<ChevronRight v-else :size="20" aria-hidden="true" />
										<!-- One line on purpose: the space between the spans is part of the button's accessible name. -->
										<span class="season-name">{{ seasonLabel(season.seasonNumber) }}</span> <span v-if="season.seasonNumber !== 0" class="season-progress">{{ season.watchedCount }} / {{ season.airedCount }} ({{ season.progress }}%)</span>
									</button>
								</h3>
								<NcButton
									v-if="season.seasonNumber !== 0 && activeCanEdit"
									:disabled="marking || season.watchedCount >= season.airedCount"
									@click="markSeasonWatched(season.seasonNumber, true)">
									{{ t('moviedb', 'Mark season watched') }}
								</NcButton>
							</div>

							<ul
								v-show="expandedSeasons.includes(season.seasonNumber)"
								:id="'season-episodes-' + season.seasonNumber"
								class="episode-list">
								<li
									v-for="ep in season.episodes"
									:key="ep.id"
									class="episode-row"
									:class="{ watched: ep.watched, unaired: !ep.aired }">
									<span class="episode-check">
										<NcCheckboxRadioSwitch
											:modelValue="ep.watched"
											:disabled="!ep.aired || marking || !activeCanEdit"
											:aria-label="t('moviedb', '{episode} watched', { episode: episodeCode(ep) + ' ' + (ep.name || t('moviedb', 'Episode {n}', { n: ep.episodeNumber })) })"
											@update:modelValue="toggleEpisode(ep, $event)" />
									</span>
									<span class="episode-code">{{ ep.episodeNumber }}</span>
									<span class="episode-name">{{ ep.name || t('moviedb', 'Episode {n}', { n: ep.episodeNumber }) }}</span>
									<span v-if="ep.airDate" class="episode-air">{{ formatDate(ep.airDate) }}</span>
									<span v-if="ep.runtime" class="episode-runtime">{{ formatRuntime(ep.runtime) }}</span>
									<span v-if="!ep.aired" class="episode-badge unaired-badge">{{ t('moviedb', 'Unaired') }}</span>
								</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</template>

		<NcEmptyContent v-else :name="t('moviedb', 'TV show not found')">
			<template #icon>
				<Television :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Delete Confirmation Dialog -->
		<NcDialog
			:open="showDeleteDialog"
			:name="t('moviedb', 'Delete TV Show')"
			@update:open="showDeleteDialog = $event">
			<p>{{ t('moviedb', 'Are you sure you want to delete this TV show? All episodes and watch history will be removed.') }}</p>
			<template #actions>
				<NcButton @click="showDeleteDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="deleteSeries">
					{{ t('moviedb', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcEmptyContent, NcLoadingIcon, NcProgressBar } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Check from 'vue-material-design-icons/Check.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Television from 'vue-material-design-icons/Television.vue'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { getLanguageFlag, LANGUAGE_OPTIONS } from '../constants.js'
import { useLibrariesStore } from '../stores/libraries.js'
import { useSeriesStore } from '../stores/series.js'
import { useSettingsStore } from '../stores/settings.js'
import { episodeCode, formatDate, formatRuntime } from '../utils/formatters.js'

export default {
	name: 'SeriesDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcDialog,
		NcProgressBar,
		NcCheckboxRadioSwitch,
		Pencil,
		Delete,
		ArrowLeft,
		Television,
		Check,
		CheckAll,
		ChevronDown,
		ChevronRight,
		EyeOff,
		PlayCircleOutline,
		Refresh,
	},

	props: {
		id: {
			type: [String, Number],
			required: true,
		},
	},

	setup() {
		const seriesStore = useSeriesStore()
		const librariesStore = useLibrariesStore()
		const settingsStore = useSettingsStore()
		return { seriesStore, librariesStore, settingsStore }
	},

	data() {
		return {
			showDeleteDialog: false,
			marking: false,
			refreshing: false,
			expandedSeasons: [],
		}
	},

	computed: {
		series() {
			return this.seriesStore.currentSeries
		},

		loading() {
			return this.seriesStore.loading
		},

		posterUrl() {
			return getPosterUrl(this.series?.posterPath, 'w500')
		},

		backdropStyle() {
			if (!this.series?.backdropPath) { return {} }
			const url = getPosterUrl(this.series.backdropPath, 'w1280')
			return {
				backgroundImage: `linear-gradient(to bottom, rgba(0,0,0,0.7), var(--color-main-background)), url(${url})`,
			}
		},

		hasWatchMeta() {
			return !!(this.series
				&& (this.series.rating || this.series.platformName
					|| this.series.languageWatched || this.series.watchedAt))
		},

		/** When the episodes were last compared with TMDB: the last refresh, else when the show was added. */
		refreshedOn() {
			return formatDate((this.series?.tmdbRefreshedAt || this.series?.createdAt || '').slice(0, 10))
		},

		activeCanEdit() {
			return this.librariesStore.activeCanEdit
		},

		stopped() {
			return this.series?.watchStatus === 'stopped'
		},

		hasAiredEpisodes() {
			return (this.series?.airedEpisodeCount ?? 0) > 0
		},

		hasEpisodes() {
			return (this.series?.seasons ?? []).some((s) => s.episodes?.length)
		},
	},

	async created() {
		// Wait for libraries so the active library id is known before fetching.
		await this.librariesStore.whenReady()
		await this.seriesStore.fetchOne(this.id)
		// Expand the first non-special season by default.
		const first = this.series?.seasons?.find((s) => s.seasonNumber !== 0)
			?? this.series?.seasons?.[0]
		if (first) {
			this.expandedSeasons = [first.seasonNumber]
		}
	},

	methods: {
		getLanguageFlag,
		formatDate,
		formatRuntime,
		languageLabel(code) {
			return LANGUAGE_OPTIONS.find((l) => l.id === code)?.label || code
		},

		episodeCode,

		seasonLabel(seasonNumber) {
			if (seasonNumber === 0) {
				return t('moviedb', 'Specials')
			}
			return t('moviedb', 'Season {n}', { n: seasonNumber })
		},

		async refreshFromTmdb() {
			if (this.refreshing) { return }
			this.refreshing = true
			try {
				await this.seriesStore.refresh(this.series.id, this.settingsStore.defaultLanguage || 'en-US')
			} finally {
				this.refreshing = false
			}
		},

		toggleSeason(seasonNumber) {
			const idx = this.expandedSeasons.indexOf(seasonNumber)
			if (idx === -1) {
				this.expandedSeasons.push(seasonNumber)
			} else {
				this.expandedSeasons.splice(idx, 1)
			}
		},

		editSeries() {
			this.$router.push({ name: 'edit-series', params: { id: this.id } })
		},

		async deleteSeries() {
			const success = await this.seriesStore.delete(this.id)
			this.showDeleteDialog = false
			if (success) {
				this.$router.push({ name: 'series' })
			}
		},

		async toggleEpisode(ep, watched) {
			if (this.marking) { return }
			this.marking = true
			await this.seriesStore.markEpisodeWatched(this.id, ep.id, watched)
			this.marking = false
		},

		async markSeasonWatched(seasonNumber, watched) {
			if (this.marking) { return }
			this.marking = true
			await this.seriesStore.markSeasonWatched(this.id, seasonNumber, watched)
			this.marking = false
		},

		async markSeriesWatched(watched) {
			if (this.marking) { return }
			this.marking = true
			await this.seriesStore.markSeriesWatched(this.id, watched)
			this.marking = false
		},

		async setStopped(stopped) {
			if (this.marking) { return }
			this.marking = true
			await this.seriesStore.setStopped(this.series.id, stopped)
			this.marking = false
		},
	},
}
</script>

<style lang="scss" scoped>
.series-detail {
    min-height: 100%;
}

.loading {
    display: flex;
    justify-content: center;
    padding: 40px;
}

.series-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 300px;
    background-size: cover;
    background-position: center;
    z-index: 0;
}

.series-content {
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

.series-poster {
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

.series-info {
    flex: 1;
    min-width: 0;
}

.series-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    // With three buttons the actions drop below the title on a narrow window
    // instead of squeezing the title or running off the right edge.
    flex-wrap: wrap;
    gap: 12px 16px;
    margin-bottom: 12px;

    .series-titles {
        flex: 1 1 240px;
        min-width: 0;

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

    .series-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    @media (max-width: 600px) {
        flex-direction: column;

        .series-titles h2 {
            font-size: 22px;
        }
    }
}

.series-meta {
    display: flex;
    gap: 16px;
    color: var(--color-text-lighter);
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.series-watch-summary {
    display: flex;
    gap: 16px;
    color: var(--color-text-maxcontrast);
    font-size: 14px;
    margin-bottom: 16px;
    flex-wrap: wrap;

    .watch-meta-rating {
        color: var(--color-warning, #e9a800);
        font-weight: 500;
    }
}

.no-episodes {
    margin: 0;
    color: var(--color-text-maxcontrast);
}

.stopped-note {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 8px 0 0;
    color: var(--color-text-maxcontrast);
}

.progress-block {
    background: var(--color-background-dark);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;

    .progress-label {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
        font-weight: 500;

        .progress-value {
            color: var(--color-text-maxcontrast);
        }
    }

    .progress-actions {
        margin-top: 12px;
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
}

.next-episode {
    margin-bottom: 16px;

    h3 {
        margin: 0 0 8px;
        font-size: 1.1em;
    }

    .next-episode-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        background: var(--color-primary-element-light);
        border-radius: 8px;
        padding: 12px 16px;
    }

    .next-episode-info {
        display: flex;
        flex-direction: column;
        gap: 2px;

        strong {
            font-size: 14px;
        }

        .next-episode-date {
            font-size: 12px;
            color: var(--color-text-maxcontrast);
        }
    }
}

.series-overview {
    line-height: 1.6;
    margin-bottom: 16px;
}

.seasons {
    margin-top: 8px;
}

.season {
    border: 1px solid var(--color-border);
    border-radius: 8px;
    margin-bottom: 12px;
    overflow: hidden;
}

.season-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 8px;
    padding: 0 16px 4px 0;
    background: var(--color-background-dark);

    // Room for the title and its progress; "Mark season watched" drops to its own line on a narrow window.
    h3 {
        margin: 0;
        font-size: 15px;
        flex: 1 1 200px;
        min-width: 0;
    }

    > :not(h3) {
        margin-inline-start: 16px;
    }
}

// The whole title row is one button: reachable by keyboard, announces expanded/collapsed.
.season-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 12px 0 12px 16px;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    text-align: start;
    cursor: pointer;
    min-height: 44px;

    &:focus-visible {
        outline: 2px solid var(--color-main-text);
        outline-offset: -2px;
        border-radius: 8px;
    }

    .season-name {
        flex: 1;
        font-weight: bold;
    }

    .season-progress {
        font-size: 13px;
        font-weight: normal;
        color: var(--color-text-maxcontrast);
    }
}

.refreshed-note {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.episode-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.episode-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 16px;
    border-top: 1px solid var(--color-border);
    font-size: 14px;

    &.watched {
        background: var(--color-success, rgba(70, 186, 97, 0.08));
    }

    &.unaired {
        opacity: 0.6;
    }

    .episode-check {
        flex-shrink: 0;
    }

    .episode-code {
        width: 24px;
        text-align: right;
        color: var(--color-text-maxcontrast);
        flex-shrink: 0;
    }

    .episode-name {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .episode-air,
    .episode-runtime {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
        flex-shrink: 0;
    }

    .episode-badge {
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 8px;
        flex-shrink: 0;
    }

    .unaired-badge {
        background: var(--color-background-darker);
        color: var(--color-text-lighter);
    }
}
</style>
