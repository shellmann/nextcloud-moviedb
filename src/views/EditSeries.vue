<template>
	<div class="edit-series">
		<div class="page-header">
			<h2>{{ t('moviedb', 'Edit TV Show') }}</h2>
		</div>

		<div v-if="loading" class="loading">
			<NcLoadingIcon :size="44" />
		</div>

		<div v-else-if="formData" class="series-form-section">
			<div class="form-group">
				<label>{{ t('moviedb', 'Title') }}</label>
				<NcTextField v-model="formData.title" required />
			</div>

			<div class="form-row-inline">
				<div class="form-group">
					<label>{{ t('moviedb', 'Platform') }}</label>
					<NcSelect v-model="selectedPlatform"
						:options="platformOptions"
						:placeholder="t('moviedb', 'Select platform')" />
				</div>
				<div class="form-group">
					<label>{{ t('moviedb', 'Language Watched') }}</label>
					<NcSelect v-model="selectedLanguage"
						:options="languageOptions"
						:placeholder="t('moviedb', 'Select language')" />
				</div>
			</div>

			<div class="form-row-inline">
				<div class="form-group">
					<label>{{ t('moviedb', 'Date Watched') }}</label>
					<NcTextField v-model="formData.watchedAt" type="date" />
				</div>
				<div class="form-group">
					<label>{{ t('moviedb', 'Rating') }}</label>
					<NcSelect v-model="selectedRating"
						:options="ratingOptions"
						:placeholder="t('moviedb', 'Select rating')" />
				</div>
			</div>

			<div class="form-group">
				<label>
					<input v-model="formData.isFavorite" type="checkbox">
					{{ t('moviedb', 'Mark as Favorite') }}
				</label>
			</div>

			<div class="form-actions">
				<NcButton @click="$router.back()">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="saveSeries">
					{{ t('moviedb', 'Update TV Show') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon, NcTextField, NcButton, NcSelect } from '@nextcloud/vue'
import { LANGUAGE_OPTIONS, getRatingOptions } from '../constants.js'
import { useSeriesStore } from '../stores/series.js'
import { usePlatformsStore } from '../stores/platforms.js'

export default {
	name: 'EditSeries',
	components: {
		NcLoadingIcon,
		NcTextField,
		NcButton,
		NcSelect,
	},
	props: {
		id: {
			type: [String, Number],
			required: true,
		},
	},
	setup() {
		const seriesStore = useSeriesStore()
		const platformsStore = usePlatformsStore()
		return { seriesStore, platformsStore }
	},
	data() {
		return {
			formData: null,
			saving: false,
			selectedPlatform: null,
			selectedLanguage: null,
			selectedRating: null,
			languageOptions: LANGUAGE_OPTIONS,
			ratingOptions: getRatingOptions(),
		}
	},
	computed: {
		series() {
			return this.seriesStore.currentSeries
		},
		loading() {
			return this.seriesStore.loading
		},
		platformOptions() {
			return this.platformsStore.platforms.map(p => ({ id: p.id, label: p.name }))
		},
	},
	watch: {
		series: {
			immediate: true,
			handler(series) {
				if (series) {
					this.formData = {
						title: series.title,
						isFavorite: !!series.isFavorite,
						watchedAt: series.watchedAt || '',
					}
					this.selectedPlatform = series.platformId
						? this.platformOptions.find(p => p.id === series.platformId) || null
						: null
					this.selectedLanguage = series.languageWatched
						? this.languageOptions.find(l => l.id === series.languageWatched) || null
						: null
					this.selectedRating = series.rating
						? this.ratingOptions.find(r => r.id === series.rating) || null
						: null
				}
			},
		},
	},
	created() {
		this.platformsStore.fetchAll()
		this.seriesStore.fetchOne(this.id)
	},
	methods: {
		async saveSeries() {
			this.saving = true
			const series = await this.seriesStore.update(this.id, {
				title: this.formData.title,
				isFavorite: this.formData.isFavorite,
				platformId: this.selectedPlatform?.id || null,
				languageWatched: this.selectedLanguage?.id || null,
				rating: this.selectedRating?.id || null,
				watchedAt: this.formData.watchedAt || null,
			})
			if (series) {
				this.$router.push({ name: 'series-detail', params: { id: this.id } })
			}
			this.saving = false
		},
	},
}
</script>

<style lang="scss" scoped>
.edit-series {
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

.loading {
    display: flex;
    justify-content: center;
    padding: 40px;
}

.series-form-section {
    background: var(--color-background-dark);
    border-radius: 8px;
    padding: 20px;

    .form-group {
        margin-bottom: 16px;

        label {
            display: block;
            margin-bottom: 4px;
            font-weight: 500;
        }

        input[type="checkbox"] {
            margin-right: 8px;
        }
    }

    .form-row-inline {
        display: flex;
        gap: 16px;

        .form-group {
            flex: 1;
        }

        @media (max-width: 600px) {
            flex-direction: column;
            gap: 0;
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
}
</style>
