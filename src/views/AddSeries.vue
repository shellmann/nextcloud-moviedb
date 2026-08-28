<template>
	<div class="add-series">
		<div class="page-header">
			<h2>{{ t('moviedb', 'Add TV Show') }}</h2>
		</div>

		<div v-if="!hasApiKey" class="api-key-warning">
			<NcNoteCard type="warning">
				<p>
					<strong>{{ t('moviedb', 'TMDB API Key Required') }}</strong><br>
					{{ t('moviedb', 'To search for TV shows and fetch metadata, you need a free TMDB API key.') }}
					<router-link :to="{ name: 'settings' }">
						{{ t('moviedb', 'Settings') }}
					</router-link>
				</p>
			</NcNoteCard>
		</div>

		<!-- Confirm section (shown when a series is selected) -->
		<div v-else-if="selectedSeries" class="series-form-section">
			<div class="form-header">
				<h3>{{ t('moviedb', 'TV Show Details') }}</h3>
				<NcButton @click="selectedSeries = null">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('moviedb', 'Back to Search') }}
				</NcButton>
			</div>

			<div class="series-preview">
				<div v-if="selectedSeries.posterPath" class="preview-poster">
					<img :src="posterUrl" :alt="selectedSeries.title">
				</div>
				<div class="preview-info">
					<h4>{{ selectedSeries.title }}</h4>
					<p v-if="selectedSeries.firstAirDate" class="preview-meta">
						{{ selectedSeries.firstAirDate.substring(0, 4) }} ·
						{{ t('moviedb', '{n} seasons', { n: selectedSeries.numberOfSeasons || 0 }) }} ·
						{{ t('moviedb', '{n} episodes', { n: selectedSeries.numberOfEpisodes || 0 }) }}
					</p>
					<p v-if="selectedSeries.overview" class="preview-overview">
						{{ selectedSeries.overview }}
					</p>
					<label class="favorite-toggle">
						<input v-model="isFavorite" type="checkbox">
						{{ t('moviedb', 'Mark as Favorite') }}
					</label>
					<NcNoteCard type="info">
						{{ t('moviedb', 'All seasons and episodes will be imported. You can then mark episodes, seasons, or the whole show as watched.') }}
					</NcNoteCard>
				</div>
			</div>

			<div class="form-actions">
				<NcButton @click="selectedSeries = null">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="saveSeries">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ saving ? t('moviedb', 'Importing episodes…') : t('moviedb', 'Add TV Show') }}
				</NcButton>
			</div>
		</div>

		<!-- Search Section (shown when no series is selected) -->
		<TmdbSearchSection v-else
			:initial-media-type="'series'"
			@select="selectSeries" />

		<!-- Duplicate Dialog -->
		<NcDialog :open="showDuplicateDialog"
			:name="t('moviedb', 'TV show already in your list')"
			@update:open="showDuplicateDialog = $event">
			<p>{{ t('moviedb', 'You have already added this TV show. Would you like to view the existing entry?') }}</p>
			<template #actions>
				<NcButton @click="showDuplicateDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="!duplicateExistingId"
					@click="viewExistingSeries">
					{{ t('moviedb', 'View existing entry') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcNoteCard, NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import TmdbSearchSection from '../components/TmdbSearchSection.vue'
import api from '../services/api.js'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { useSeriesStore } from '../stores/series.js'
import { useSettingsStore } from '../stores/settings.js'

export default {
	name: 'AddSeries',
	components: {
		NcNoteCard,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		ArrowLeft,
		TmdbSearchSection,
	},
	setup() {
		const seriesStore = useSeriesStore()
		const settingsStore = useSettingsStore()
		return { seriesStore, settingsStore }
	},
	data() {
		return {
			selectedSeries: null,
			isFavorite: false,
			saving: false,
			showDuplicateDialog: false,
			duplicateExistingId: null,
		}
	},
	computed: {
		hasApiKey() {
			return this.settingsStore.hasApiKey
		},
		tmdbLanguage() {
			return this.settingsStore.defaultLanguage || 'en-US'
		},
		posterUrl() {
			return getPosterUrl(this.selectedSeries?.posterPath, 'w300')
		},
	},
	methods: {
		async selectSeries(item) {
			try {
				const response = await api.getTmdbSeriesDetails(item.id, this.tmdbLanguage)
				const details = response.data.series

				this.selectedSeries = {
					tmdbId: details.id,
					title: details.name,
					originalTitle: details.original_name,
					posterPath: details.poster_path,
					backdropPath: details.backdrop_path,
					overview: details.overview,
					genreIds: details.genres?.map(g => g.id) || [],
					firstAirDate: details.first_air_date,
					numberOfSeasons: details.number_of_seasons,
					numberOfEpisodes: details.number_of_episodes,
					status: details.status,
					castData: details.cast,
					director: details.director,
					// TMDB season list drives per-season episode fetch server-side.
					seasons: details.seasons || [],
				}
				this.isFavorite = false
				window.scrollTo({ top: 0, behavior: 'smooth' })
			} catch (error) {
				console.error('Failed to fetch series details:', error)
				showError(t('moviedb', 'Failed to load TV show details.'))
			}
		},
		async saveSeries() {
			if (this.saving) return

			this.saving = true
			const result = await this.seriesStore.create({
				...this.selectedSeries,
				isFavorite: this.isFavorite,
				language: this.tmdbLanguage,
			})
			this.saving = false

			if (result?.duplicate) {
				this.duplicateExistingId = result.existingId
				this.showDuplicateDialog = true
				return
			}
			if (result) {
				this.$router.push({ name: 'series-detail', params: { id: String(result.id) } })
			}
		},
		viewExistingSeries() {
			if (!this.duplicateExistingId) return
			const id = this.duplicateExistingId
			this.showDuplicateDialog = false
			this.$router.push({ name: 'series-detail', params: { id: String(id) } })
		},
	},
}
</script>

<style lang="scss" scoped>
.add-series {
	padding: 20px;
	max-width: 900px;
	margin: 0 auto;
}

.page-header {
	margin-bottom: 20px;

	h2 {
		margin: 0;
		font-size: 24px;
	}
}

.api-key-warning {
	margin-bottom: 20px;
}

.series-form-section {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 20px;

	.form-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 16px;

		h3 {
			margin: 0;
		}
	}
}

.series-preview {
	display: flex;
	gap: 24px;

	@media (max-width: 600px) {
		flex-direction: column;
	}
}

.preview-poster {
	flex-shrink: 0;
	width: 150px;

	img {
		width: 100%;
		border-radius: 8px;
	}
}

.preview-info {
	flex: 1;

	h4 {
		margin: 0 0 8px;
		font-size: 20px;
	}

	.preview-meta {
		color: var(--color-text-lighter);
		margin: 0 0 12px;
	}

	.preview-overview {
		line-height: 1.5;
		margin: 0 0 16px;
	}

	.favorite-toggle {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 16px;
	}
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
