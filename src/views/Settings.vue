<template>
	<div class="settings">
		<div class="page-header">
			<h2>{{ t('moviedb', 'Settings') }}</h2>
		</div>

		<div class="settings-section">
			<h3>{{ t('moviedb', 'TMDB API Configuration') }}</h3>
			<p class="section-description">
				{{ t('moviedb', 'To search for movies and fetch metadata, you need a free TMDB API key.') }}
				<a href="https://www.themoviedb.org/settings/api" target="_blank">{{ t('moviedb', 'Get your API key here') }}</a>.
			</p>

			<div class="form-group api-key-group">
				<div class="api-key-field">
					<label>{{ t('moviedb', 'TMDB API Key (Read Access Token)') }}</label>
					<div v-if="hasApiKey" class="api-key-status">
						<span class="status-indicator status-saved">{{ t('moviedb', 'API key configured') }}</span>
					</div>
					<div v-else class="api-key-status">
						<span class="status-indicator status-missing">{{ t('moviedb', 'No API key') }}</span>
					</div>
					<NcTextField
						v-model="tmdbApiKey"
						:type="showApiKey ? 'text' : 'password'"
						:placeholder="hasApiKey ? t('moviedb', 'Enter new key to update') : t('moviedb', 'Enter your TMDB API key')" />
				</div>
				<NcButton @click="showApiKey = !showApiKey">
					<template #icon>
						<Eye v-if="!showApiKey" :size="20" />
						<EyeOff v-else :size="20" />
					</template>
				</NcButton>
				<NcButton
					v-if="hasApiKey"
					variant="error"
					@click="removeApiKey">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('moviedb', 'Remove API Key') }}
				</NcButton>
			</div>

			<div class="form-group">
				<label>{{ t('moviedb', 'Default TMDB Language') }}</label>
				<NcSelect
					v-model="selectedLanguage"
					:options="languageOptions"
					:placeholder="t('moviedb', 'Select language')" />
				<p class="hint">
					{{ t('moviedb', 'Language used for fetching movie metadata from TMDB (title, description, genres).') }}
				</p>
				<p class="hint hint-note">
					{{ t('moviedb', 'Note: Movie metadata is stored when you add a movie. Changing this setting only affects newly added movies - existing movies will keep their original language.') }}
				</p>
			</div>

			<NcButton variant="primary" :disabled="saving" @click="saveSettings">
				<template #icon>
					<ContentSave :size="20" />
				</template>
				{{ t('moviedb', 'Save Settings') }}
			</NcButton>
		</div>

		<div class="settings-section">
			<h3>{{ t('moviedb', 'Custom Platforms') }}</h3>
			<p class="section-description">
				{{ t('moviedb', 'Add your own streaming platforms or sources.') }}
			</p>

			<div class="platform-list">
				<div
					v-for="platform in customPlatforms"
					:key="platform.id"
					class="platform-item">
					<span>{{ platform.name }}</span>
					<NcButton variant="error" @click="deletePlatform(platform.id)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<div class="add-platform">
				<NcTextField
					v-model="newPlatformName"
					:placeholder="t('moviedb', 'Platform name')" />
				<NcButton :disabled="!newPlatformName" @click="addPlatform">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('moviedb', 'Add Platform') }}
				</NcButton>
			</div>
		</div>

		<div class="settings-section">
			<h3>{{ t('moviedb', 'Import / Export') }}</h3>
			<p class="section-description">
				{{ t('moviedb', 'Export the active library as a JSON file to back it up or move it to another Nextcloud instance. The file contains your ratings and reviews, but not your TMDB API key.') }}
			</p>
			<NcButton :disabled="exporting" @click="exportLibrary">
				<template #icon>
					<Download :size="20" />
				</template>
				{{ t('moviedb', 'Export library') }}
			</NcButton>

			<p class="section-description import-description">
				{{ t('moviedb', 'Import a file that was exported from MovieDB into the active library. Titles that are already in the library are skipped.') }}
			</p>
			<NcButton :disabled="importing || !canEdit" @click="chooseImportFile">
				<template #icon>
					<Upload :size="20" />
				</template>
				{{ importing ? t('moviedb', 'Importing…') : t('moviedb', 'Import library') }}
			</NcButton>
			<p v-if="!canEdit" class="section-description">
				{{ t('moviedb', 'You need edit permission for this library to import.') }}
			</p>
			<input
				ref="importInput"
				type="file"
				accept=".json,application/json"
				class="import-input"
				@change="onImportFileChosen">
		</div>

		<div class="settings-section">
			<h3>{{ t('moviedb', 'About') }}</h3>
			<p class="app-version">
				MovieDB v{{ appVersion }}
			</p>
			<p class="about-links">
				<a :href="repoUrl" target="_blank" rel="noopener noreferrer">{{ t('moviedb', 'Source code') }}</a>
				<a :href="repoUrl + '/issues'" target="_blank" rel="noopener noreferrer">{{ t('moviedb', 'Report an issue') }}</a>
				<a href="https://apps.nextcloud.com/apps/moviedb" target="_blank" rel="noopener noreferrer">{{ t('moviedb', 'App Store') }}</a>
				<a :href="repoUrl + '/blob/main/LICENSE'" target="_blank" rel="noopener noreferrer">{{ t('moviedb', 'License') }} (AGPL-3.0-or-later)</a>
			</p>
			<a
				href="https://www.themoviedb.org/"
				target="_blank"
				rel="noopener noreferrer"
				class="tmdb-logo-link">
				<img :src="tmdbLogoUrl" alt="The Movie Database (TMDB)" class="tmdb-logo">
			</a>
			<p class="section-description tmdb-notice">
				{{ t('moviedb', 'This application uses TMDB and the TMDB APIs but is not endorsed, certified, or otherwise approved by TMDB.') }}
			</p>
		</div>

		<!-- Delete Platform Confirmation Dialog -->
		<NcDialog
			:open="showDeletePlatformDialog"
			:name="t('moviedb', 'Delete Platform')"
			@update:open="showDeletePlatformDialog = $event">
			<p>{{ t('moviedb', 'Delete this platform?') }}</p>
			<template #actions>
				<NcButton @click="showDeletePlatformDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="confirmDeletePlatform">
					{{ t('moviedb', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Remove API Key Confirmation Dialog -->
		<NcDialog
			:open="showRemoveApiKeyDialog"
			:name="t('moviedb', 'Remove API Key')"
			@update:open="showRemoveApiKeyDialog = $event">
			<p>{{ t('moviedb', 'Remove API key?') }}</p>
			<template #actions>
				<NcButton @click="showRemoveApiKeyDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="confirmRemoveApiKey">
					{{ t('moviedb', 'Remove') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Import Confirmation Dialog -->
		<NcDialog
			:open="showImportDialog"
			:name="t('moviedb', 'Import into {library}?', { library: activeLibraryName })"
			@update:open="onImportDialogToggle">
			<div v-if="importSummary" class="import-dialog">
				<p>{{ t('moviedb', 'This file contains:') }}</p>
				<ul class="import-counts">
					<li>{{ t('moviedb', 'Movies: {count}', { count: importSummary.movies }) }}</li>
					<li>{{ t('moviedb', 'TV shows: {count}, episodes: {episodes}', { count: importSummary.series, episodes: importSummary.episodes }) }}</li>
					<li>{{ t('moviedb', 'Watchlist items: {count}', { count: importSummary.watchlist }) }}</li>
				</ul>
				<p>{{ t('moviedb', 'Titles that already exist in this library are skipped. The import cannot be undone automatically.') }}</p>
				<p v-if="isSharedLibrary" class="import-warning">
					{{ t('moviedb', 'This library may be shared: other members will see the imported items.') }}
				</p>
				<p class="hint">
					{{ t('moviedb', 'Only import files that you exported from MovieDB yourself.') }}
				</p>
			</div>
			<template #actions>
				<NcButton @click="cancelImport">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="importing" @click="confirmImport">
					{{ t('moviedb', 'Import') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Import Result Dialog -->
		<NcDialog
			:open="importResult !== null"
			:name="t('moviedb', 'Import finished')"
			@update:open="importResult = null">
			<div v-if="importResult" class="import-dialog">
				<p>{{ t('moviedb', 'Imported:') }}</p>
				<ul class="import-counts">
					<li>{{ t('moviedb', 'Movies: {count}', { count: importResult.imported.movies }) }}</li>
					<li>{{ t('moviedb', 'TV shows: {count}, episodes: {episodes}', { count: importResult.imported.series, episodes: importResult.imported.episodes }) }}</li>
					<li>{{ t('moviedb', 'Watchlist items: {count}', { count: importResult.imported.watchlist }) }}</li>
					<li>{{ t('moviedb', 'Watches: {count}', { count: importResult.imported.watches }) }}</li>
				</ul>
				<p v-if="skippedCount > 0">
					{{ t('moviedb', 'Skipped (already in the library): {count}', { count: skippedCount }) }}
				</p>
				<p v-if="invalidCount > 0">
					{{ t('moviedb', 'Ignored invalid entries: {count}', { count: invalidCount }) }}
				</p>
			</div>
			<template #actions>
				<NcButton variant="primary" @click="importResult = null">
					{{ t('moviedb', 'Close') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { imagePath } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import { getTmdbLanguageOptions } from '../constants.js'
import api from '../services/api.js'
import { useLibrariesStore } from '../stores/libraries.js'
import { useMoviesStore } from '../stores/movies.js'
import { usePlatformsStore } from '../stores/platforms.js'
import { useSeriesStore } from '../stores/series.js'
import { useSettingsStore } from '../stores/settings.js'
import { useWatchlistStore } from '../stores/watchlist.js'
import { downloadBlob, filenameFromDisposition, LibraryFileError, summarizeExport } from '../utils/libraryFile.js'

export default {
	name: 'Settings',
	components: {
		NcTextField,
		NcSelect,
		NcButton,
		NcDialog,
		Eye,
		EyeOff,
		ContentSave,
		Delete,
		Download,
		Plus,
		Upload,
	},

	setup() {
		const settingsStore = useSettingsStore()
		const platformsStore = usePlatformsStore()
		const librariesStore = useLibrariesStore()
		const moviesStore = useMoviesStore()
		const seriesStore = useSeriesStore()
		const watchlistStore = useWatchlistStore()
		return { settingsStore, platformsStore, librariesStore, moviesStore, seriesStore, watchlistStore }
	},

	data() {
		return {
			tmdbApiKey: '',
			showApiKey: false,
			repoUrl: 'https://github.com/shellmann/nextcloud-moviedb',
			selectedLanguage: null,
			languageOptions: getTmdbLanguageOptions(),
			newPlatformName: '',
			saving: false,
			showDeletePlatformDialog: false,
			showRemoveApiKeyDialog: false,
			pendingDeletePlatformId: null,
			exporting: false,
			importing: false,
			importFile: null,
			importSummary: null,
			showImportDialog: false,
			importResult: null,
		}
	},

	computed: {
		appVersion() {
			// eslint-disable-next-line no-undef
			return typeof __APP_VERSION__ !== 'undefined' ? __APP_VERSION__ : 'unknown'
		},

		customPlatforms() {
			return this.platformsStore.customPlatforms
		},

		tmdbLogoUrl() {
			return imagePath('moviedb', 'tmdb-logo.svg')
		},

		hasApiKey() {
			return this.settingsStore.hasApiKey
		},

		canEdit() {
			return this.librariesStore.activeCanEdit
		},

		activeLibraryName() {
			return this.librariesStore.activeLibrary?.name ?? ''
		},

		isSharedLibrary() {
			const lib = this.librariesStore.activeLibrary
			return lib !== null && !lib.isPersonal
		},

		skippedCount() {
			const skipped = this.importResult?.skippedDuplicates ?? {}
			return Object.values(skipped).reduce((sum, n) => sum + n, 0)
		},

		invalidCount() {
			const invalid = this.importResult?.invalid ?? {}
			return Object.values(invalid).reduce((sum, n) => sum + n, 0)
		},
	},

	mounted() {
		// Set initial language from store
		const defaultLang = this.settingsStore.defaultLanguage
		this.selectedLanguage = this.languageOptions.find((l) => l.id === defaultLang) || this.languageOptions[0]
	},

	methods: {
		async saveSettings() {
			this.saving = true
			try {
				await this.settingsStore.update({
					tmdbApiKey: this.tmdbApiKey || undefined,
					defaultLanguage: this.selectedLanguage?.id,
				})
				showSuccess(t('moviedb', 'Settings saved successfully.'))
				this.tmdbApiKey = '' // Clear the field after save
			} catch {
				showError(t('moviedb', 'Failed to save settings. Please try again.'))
			} finally {
				this.saving = false
			}
		},

		async addPlatform() {
			if (!this.newPlatformName) { return }

			try {
				await this.platformsStore.create({
					name: this.newPlatformName,
				})
				showSuccess(t('moviedb', 'Platform created successfully.'))
				this.newPlatformName = ''
			} catch {
				showError(t('moviedb', 'Failed to create platform. Please try again.'))
			}
		},

		async deletePlatform(id) {
			this.pendingDeletePlatformId = id
			this.showDeletePlatformDialog = true
		},

		async confirmDeletePlatform() {
			try {
				await this.platformsStore.delete(this.pendingDeletePlatformId)
				showSuccess(t('moviedb', 'Platform deleted successfully.'))
			} catch {
				showError(t('moviedb', 'Failed to delete platform. Please try again.'))
			} finally {
				this.showDeletePlatformDialog = false
				this.pendingDeletePlatformId = null
			}
		},

		async exportLibrary() {
			this.exporting = true
			try {
				const libraryId = this.librariesStore.activeLibraryId
				const response = await api.exportLibrary(libraryId !== null ? libraryId : undefined)
				downloadBlob(response.data, filenameFromDisposition(response.headers?.['content-disposition']))
				showSuccess(t('moviedb', 'Library exported.'))
			} catch {
				showError(t('moviedb', 'Failed to export the library. Please try again.'))
			} finally {
				this.exporting = false
			}
		},

		chooseImportFile() {
			this.$refs.importInput.click()
		},

		async onImportFileChosen(event) {
			const input = event.target
			const file = input.files?.[0]
			// Reset so choosing the same file again still fires change.
			input.value = ''
			if (!file) { return }

			try {
				this.importSummary = summarizeExport(JSON.parse(await file.text()))
				this.importFile = file
				this.showImportDialog = true
			} catch (error) {
				if (error instanceof LibraryFileError && error.code === 'newerVersion') {
					showError(t('moviedb', 'This file was created by a newer version of MovieDB. Please update the app first.'))
				} else {
					showError(t('moviedb', 'This is not a MovieDB export file.'))
				}
			}
		},

		onImportDialogToggle(open) {
			if (!open) { this.cancelImport() }
		},

		cancelImport() {
			this.showImportDialog = false
			this.importFile = null
			this.importSummary = null
		},

		async confirmImport() {
			if (!this.importFile) { return }
			this.importing = true
			try {
				const libraryId = this.librariesStore.activeLibraryId
				const response = await api.importLibrary(this.importFile, libraryId !== null ? libraryId : undefined)
				this.importResult = response.data
				await this.refreshAfterImport()
			} catch (error) {
				const status = error.response?.status
				if (status === 413) {
					showError(t('moviedb', 'The file is too large.'))
				} else if (status === 429) {
					showError(t('moviedb', 'Too many imports. Please try again later.'))
				} else if (status === 400) {
					showError(t('moviedb', 'The file is not a valid MovieDB export.'))
				} else {
					showError(t('moviedb', 'The import failed. Nothing was changed.'))
				}
			} finally {
				this.importing = false
				this.cancelImport()
			}
		},

		async refreshAfterImport() {
			this.moviesStore.resetFilters()
			this.seriesStore.resetFilters()
			this.watchlistStore.resetFilters()
			await Promise.all([
				this.moviesStore.fetchAll(),
				this.seriesStore.fetchAll(),
				this.watchlistStore.fetchAll(),
				this.platformsStore.fetchAll(),
			])
		},

		async removeApiKey() {
			this.showRemoveApiKeyDialog = true
		},

		async confirmRemoveApiKey() {
			try {
				await this.settingsStore.update({ tmdbApiKey: '' })
				showSuccess(t('moviedb', 'API key removed successfully.'))
			} catch {
				showError(t('moviedb', 'Failed to remove API key. Please try again.'))
			} finally {
				this.showRemoveApiKeyDialog = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.settings {
    padding: 20px;
    max-width: 700px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 20px;

    h2 {
        margin: 0;
        font-size: 24px;
    }
}

.settings-section {
    background: var(--color-background-dark);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;

    h3 {
        margin: 0 0 8px;
    }

    .section-description {
        color: var(--color-text-lighter);
        margin-bottom: 16px;

        a {
            color: var(--color-primary);
        }
    }
}

.form-group {
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;

    &.api-key-group {
        flex-direction: row;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    label {
        display: block;
        font-weight: bold;
    }

    .hint {
        font-size: 12px;
        color: var(--color-text-lighter);
        margin: 4px 0 0;

        &.hint-note {
            font-style: italic;
            padding: 8px;
            background: var(--color-background-darker);
            border-radius: 4px;
            margin-top: 8px;
        }
    }
}

.platform-list {
    margin-bottom: 16px;
}

.platform-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: var(--color-background-darker);
    border-radius: 4px;
    margin-bottom: 8px;
}

.add-platform {
    display: flex;
    gap: 8px;

    .button-vue {
        flex-shrink: 0;
    }
}

.api-key-field {
    flex: 1;
    min-width: 200px;
}

.api-key-status {
    margin-bottom: 8px;
}

.status-indicator {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}

.status-saved {
    background: var(--color-success);
    color: #000;
    font-weight: bold;
}

.status-missing {
    background: var(--color-warning);
    color: #000;
    font-weight: bold;
}

.tmdb-logo-link {
    display: inline-block;
    margin: 8px 0 12px;
}

.tmdb-logo {
    display: block;
    height: 14px;
    width: auto;
}

.settings-section .section-description.tmdb-notice {
    margin-bottom: 0;
}

.app-version {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    margin-bottom: 8px;
}

.about-links {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 16px;
    margin-bottom: 16px;
}
.import-description {
    margin-top: 20px;
}

.import-input {
    display: none;
}

.import-dialog {
    padding: 0 8px 8px;

    .import-counts {
        margin: 4px 0 12px 20px;
        list-style: disc;
    }

    .import-warning {
        font-weight: bold;
    }

    .hint {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
    }
}
</style>
