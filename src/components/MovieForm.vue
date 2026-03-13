<template>
	<div class="movie-form">
		<div class="form-row">
			<div v-if="movie.posterPath" class="form-poster">
				<img :src="posterUrl" :alt="movie.title">
			</div>
			<div class="form-fields">
				<div class="form-group">
					<label>{{ t('moviedb', 'Title') }}</label>
					<NcTextField v-model="formData.title" required />
				</div>

				<div class="form-group">
					<label>{{ t('moviedb', 'Original Title') }}</label>
					<NcTextField v-model="formData.originalTitle" :disabled="true" />
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
							:placeholder="t('moviedb', 'Select language')"
							@update:modelValue="onLanguageChange" />
					</div>
				</div>

				<div class="form-row-inline">
					<div class="form-group">
						<label>{{ t('moviedb', 'Date Watched') }}</label>
						<NcTextField v-model="formData.dateWatched" type="date" />
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

				<div class="form-group">
					<label>{{ t('moviedb', 'Review / Notes') }}</label>
					<textarea v-model="formData.review"
						rows="4"
						:placeholder="t('moviedb', 'Write your thoughts about the movie...')" />
				</div>
			</div>
		</div>

		<div class="form-actions">
			<NcButton @click="$emit('cancel')">
				{{ t('moviedb', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || isSubmitting" @click="submit">
				{{ editMode ? t('moviedb', 'Update Movie') : t('moviedb', 'Save Movie') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcSelect, NcButton } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'
import { LANGUAGE_OPTIONS, getRatingOptions } from '../constants.js'
import { getPosterUrl } from '../composables/usePosterUrl.js'

/**
 * MovieForm component - Form for creating or editing movie entries.
 * Handles movie metadata input including platform, language, rating, and review.
 */
export default {
	name: 'MovieForm',
	components: {
		NcTextField,
		NcSelect,
		NcButton,
	},
	props: {
		/**
		 * Movie object with TMDB data and user-editable fields
		 * @type {{ title: string, originalTitle?: string, tmdbId?: number, posterPath?: string, platformId?: number, languageWatched?: string, dateWatched?: string, rating?: number, isFavorite?: boolean, review?: string }}
		 */
		movie: {
			type: Object,
			required: true,
		},
		/**
		 * Available streaming platforms for selection
		 * @type {Array<{ id: number, name: string }>}
		 */
		platforms: {
			type: Array,
			default: () => [],
		},
		/**
		 * Whether the form is currently saving (shows loading state)
		 */
		saving: {
			type: Boolean,
			default: false,
		},
		/**
		 * Whether the form is in edit mode (vs create mode)
		 */
		editMode: {
			type: Boolean,
			default: false,
		},
	},
	emits: [
		/**
		 * Emitted when form is submitted with complete movie data
		 * @param {object} movieData - The form data to save
		 */
		'submit',
		/**
		 * Emitted when user cancels the form
		 */
		'cancel',
	],
	data() {
		return {
			formData: { ...this.movie },
			selectedPlatform: null,
			selectedLanguage: null,
			selectedRating: null,
			languageOptions: LANGUAGE_OPTIONS,
			ratingOptions: getRatingOptions(),
			isInitializing: false,
			isSubmitting: false,
		}
	},
	computed: {
		platformOptions() {
			return this.platforms.map(p => ({ id: p.id, label: p.name }))
		},
		posterUrl() {
			return getPosterUrl(this.movie.posterPath, 'w300')
		},
	},
	watch: {
		movie: {
			immediate: true,
			handler(movie) {
				this.isInitializing = true
				this.isSubmitting = false
				this.formData = { ...movie }
				if (movie.platformId) {
					this.selectedPlatform = this.platformOptions.find(p => p.id === movie.platformId)
				}
				if (movie.languageWatched) {
					this.selectedLanguage = this.languageOptions.find(l => l.id === movie.languageWatched)
				} else {
					// Default to English (first in list)
					this.selectedLanguage = this.languageOptions[0]
				}
				if (movie.rating) {
					this.selectedRating = this.ratingOptions.find(r => r.id === movie.rating)
				}
				this.$nextTick(() => {
					this.isInitializing = false
				})
			},
		},
	},
	methods: {
		/**
		 * Handles language selection change by fetching localized movie title.
		 * @param {object} language - Selected language option
		 */
		async onLanguageChange(language) {
			// Skip API call during initialization (watcher sets language)
			if (this.isInitializing) return
			if (!language || !this.formData.tmdbId) return

			// Fetch movie title in the selected language
			try {
				const response = await api.getTmdbMovieDetails(this.formData.tmdbId, language.tmdbCode)
				const details = response.data.movie
				if (details.title) {
					this.formData.title = details.title
				}
			} catch (error) {
				console.error('Failed to fetch localized title:', error)
				showError(t('moviedb', 'Failed to fetch localized title.'))
			}
		},
		/**
		 * Submits the form data to the parent component.
		 */
		submit() {
			// Prevent double submission
			if (this.saving || this.isSubmitting) return
			this.isSubmitting = true

			this.$emit('submit', {
				...this.formData,
				platformId: this.selectedPlatform?.id || null,
				languageWatched: this.selectedLanguage?.id || null,
				rating: this.selectedRating?.id || null,
			})
		},
	},
}
</script>

<style lang="scss" scoped>
.movie-form {
    .form-row {
        display: flex;
        gap: 24px;

        @media (max-width: 600px) {
            flex-direction: column;
        }
    }

    .form-poster {
        flex-shrink: 0;
        width: 150px;

        img {
            width: 100%;
            border-radius: 8px;
        }
    }

    .form-fields {
        flex: 1;
    }

    .form-row-inline {
        display: flex;
        gap: 16px;

        > .form-group {
            flex: 1;
        }

        @media (max-width: 500px) {
            flex-direction: column;
        }
    }

    .form-group {
        margin-bottom: 16px;

        label {
            display: block;
            margin-bottom: 4px;
            font-weight: 500;
        }

        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            background: var(--color-main-background);
            color: var(--color-main-text);
            resize: vertical;

            &:focus {
                border-color: var(--color-primary);
                outline: none;
            }
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
