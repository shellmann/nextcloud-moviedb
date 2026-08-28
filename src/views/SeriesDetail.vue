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
							<NcButton @click="editSeries">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('moviedb', 'Edit') }}
							</NcButton>
							<NcButton type="error" @click="showDeleteDialog = true">
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

					<!-- Overall progress -->
					<div class="progress-block">
						<div class="progress-label">
							<span>{{ t('moviedb', 'Progress') }}</span>
							<span class="progress-value">
								{{ series.watchedEpisodeCount }} / {{ series.airedEpisodeCount }}
								({{ series.progress }}%)
							</span>
						</div>
						<NcProgressBar :value="series.progress" size="medium" />
						<div class="progress-actions">
							<NcButton :disabled="series.caughtUp || marking"
								@click="markSeriesWatched">
								<template #icon>
									<CheckAll :size="20" />
								</template>
								{{ series.caughtUp ? t('moviedb', 'Caught up') : t('moviedb', 'Mark series watched') }}
							</NcButton>
						</div>
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
							<NcButton type="primary"
								:disabled="marking"
								@click="markEpisodeWatched(series.nextEpisode.id)">
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
						<div v-for="season in series.seasons"
							:key="season.seasonNumber"
							class="season">
							<div class="season-header" @click="toggleSeason(season.seasonNumber)">
								<ChevronDown v-if="expandedSeasons.includes(season.seasonNumber)" :size="20" />
								<ChevronRight v-else :size="20" />
								<h3>{{ seasonLabel(season.seasonNumber) }}</h3>
								<span v-if="season.seasonNumber !== 0" class="season-progress">
									{{ season.watchedCount }} / {{ season.airedCount }} ({{ season.progress }}%)
								</span>
								<NcButton v-if="season.seasonNumber !== 0"
									:disabled="marking || season.watchedCount >= season.airedCount"
									@click.stop="markSeasonWatched(season.seasonNumber)">
									{{ t('moviedb', 'Mark season watched') }}
								</NcButton>
							</div>

							<ul v-show="expandedSeasons.includes(season.seasonNumber)" class="episode-list">
								<li v-for="ep in season.episodes"
									:key="ep.id"
									class="episode-row"
									:class="{ watched: ep.watched, unaired: !ep.aired }">
									<span class="episode-code">{{ ep.episodeNumber }}</span>
									<span class="episode-name">{{ ep.name || t('moviedb', 'Episode {n}', { n: ep.episodeNumber }) }}</span>
									<span v-if="ep.airDate" class="episode-air">{{ formatDate(ep.airDate) }}</span>
									<span v-if="ep.runtime" class="episode-runtime">{{ formatRuntime(ep.runtime) }}</span>
									<span v-if="ep.watchCount > 1" class="episode-watchcount" :title="t('moviedb', 'Watched {n} times', { n: ep.watchCount })">×{{ ep.watchCount }}</span>
									<span v-if="!ep.aired" class="episode-badge unaired-badge">{{ t('moviedb', 'Unaired') }}</span>
									<div class="episode-actions">
										<NcButton v-if="!ep.watched"
											:disabled="!ep.aired || marking"
											:aria-label="t('moviedb', 'Mark watched')"
											@click="markEpisodeWatched(ep.id)">
											<template #icon>
												<Check :size="18" />
											</template>
										</NcButton>
										<template v-else>
											<Check :size="18" class="watched-check" />
											<NcButton :aria-label="t('moviedb', 'Log again')"
												:title="t('moviedb', 'Log again')"
												@click="openLog(ep)">
												<template #icon>
													<Plus :size="18" />
												</template>
											</NcButton>
										</template>
									</div>
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
		<NcDialog :open="showDeleteDialog"
			:name="t('moviedb', 'Delete TV Show')"
			@update:open="showDeleteDialog = $event">
			<p>{{ t('moviedb', 'Are you sure you want to delete this TV show? All episodes and watch history will be removed.') }}</p>
			<template #actions>
				<NcButton @click="showDeleteDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="deleteSeries">
					{{ t('moviedb', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Log rewatch dialog -->
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
import { NcButton, NcLoadingIcon, NcEmptyContent, NcDialog, NcProgressBar } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Television from 'vue-material-design-icons/Television.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Check from 'vue-material-design-icons/Check.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import RatingStars from '../components/RatingStars.vue'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { formatDate, formatRuntime } from '../utils/formatters.js'
import { useSeriesStore } from '../stores/series.js'
import { useEpisodeWatchesStore } from '../stores/episodeWatches.js'

export default {
	name: 'SeriesDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcDialog,
		NcProgressBar,
		Pencil,
		Delete,
		ArrowLeft,
		Television,
		Plus,
		Check,
		CheckAll,
		ChevronDown,
		ChevronRight,
		RatingStars,
	},
	props: {
		id: {
			type: [String, Number],
			required: true,
		},
	},
	setup() {
		const seriesStore = useSeriesStore()
		const episodeWatchesStore = useEpisodeWatchesStore()
		return { seriesStore, episodeWatchesStore }
	},
	data() {
		return {
			showDeleteDialog: false,
			showLogDialog: false,
			marking: false,
			expandedSeasons: [],
			logEpisodeId: null,
			logForm: {
				watchedAt: new Date().toISOString().slice(0, 10),
				rating: null,
				review: '',
			},
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
			if (!this.series?.backdropPath) return {}
			const url = getPosterUrl(this.series.backdropPath, 'w1280')
			return {
				backgroundImage: `linear-gradient(to bottom, rgba(0,0,0,0.7), var(--color-main-background)), url(${url})`,
			}
		},
	},
	async created() {
		await this.seriesStore.fetchOne(this.id)
		// Expand the first non-special season by default.
		const first = this.series?.seasons?.find(s => s.seasonNumber !== 0)
			?? this.series?.seasons?.[0]
		if (first) {
			this.expandedSeasons = [first.seasonNumber]
		}
	},
	methods: {
		formatDate,
		formatRuntime,
		episodeCode(ep) {
			const s = String(ep.seasonNumber).padStart(2, '0')
			const e = String(ep.episodeNumber).padStart(2, '0')
			return `S${s}E${e}`
		},
		seasonLabel(seasonNumber) {
			if (seasonNumber === 0) {
				return t('moviedb', 'Specials')
			}
			return t('moviedb', 'Season {n}', { n: seasonNumber })
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
		async markEpisodeWatched(episodeId) {
			if (this.marking) return
			this.marking = true
			await this.seriesStore.markEpisodeWatched(this.id, episodeId)
			this.marking = false
		},
		async markSeasonWatched(seasonNumber) {
			if (this.marking) return
			this.marking = true
			await this.seriesStore.markSeasonWatched(this.id, seasonNumber)
			this.marking = false
		},
		async markSeriesWatched() {
			if (this.marking) return
			this.marking = true
			await this.seriesStore.markSeriesWatched(this.id)
			this.marking = false
		},
		openLog(ep) {
			this.logEpisodeId = ep.id
			this.logForm = { watchedAt: new Date().toISOString().slice(0, 10), rating: null, review: '' }
			this.showLogDialog = true
		},
		async submitLog() {
			if (!this.logEpisodeId) return
			const data = {
				watchedAt: this.logForm.watchedAt || null,
				rating: this.logForm.rating || null,
				review: this.logForm.review || null,
			}
			await this.episodeWatchesStore.create(this.logEpisodeId, data)
			this.showLogDialog = false
			// Refresh progress / watch counts.
			await this.seriesStore.fetchOne(this.id)
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
    gap: 16px;
    margin-bottom: 12px;

    .series-titles {
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

    .series-actions {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
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
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    cursor: pointer;
    background: var(--color-background-dark);

    h3 {
        margin: 0;
        font-size: 15px;
        flex: 1;
    }

    .season-progress {
        font-size: 13px;
        color: var(--color-text-maxcontrast);
    }
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
    .episode-runtime,
    .episode-watchcount {
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

    .episode-actions {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-left: auto;
        flex-shrink: 0;
    }

    .watched-check {
        color: var(--color-success, #46ba61);
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
