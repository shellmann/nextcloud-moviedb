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
import { NcLoadingIcon, NcTextField, NcButton } from '@nextcloud/vue'
import { useSeriesStore } from '../stores/series.js'

export default {
	name: 'EditSeries',
	components: {
		NcLoadingIcon,
		NcTextField,
		NcButton,
	},
	props: {
		id: {
			type: [String, Number],
			required: true,
		},
	},
	setup() {
		const seriesStore = useSeriesStore()
		return { seriesStore }
	},
	data() {
		return {
			formData: null,
			saving: false,
		}
	},
	computed: {
		series() {
			return this.seriesStore.currentSeries
		},
		loading() {
			return this.seriesStore.loading
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
					}
				}
			},
		},
	},
	created() {
		this.seriesStore.fetchOne(this.id)
	},
	methods: {
		async saveSeries() {
			this.saving = true
			const series = await this.seriesStore.update(this.id, {
				title: this.formData.title,
				isFavorite: this.formData.isFavorite,
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
