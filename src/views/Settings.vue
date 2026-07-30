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
					<NcTextField v-model="tmdbApiKey"
						:type="showApiKey ? 'text' : 'password'"
						:placeholder="hasApiKey ? t('moviedb', 'Enter new key to update') : t('moviedb', 'Enter your TMDB API key')" />
				</div>
				<NcButton @click="showApiKey = !showApiKey">
					<template #icon>
						<Eye v-if="!showApiKey" :size="20" />
						<EyeOff v-else :size="20" />
					</template>
				</NcButton>
				<NcButton v-if="hasApiKey"
					type="error"
					@click="removeApiKey">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('moviedb', 'Remove API Key') }}
				</NcButton>
			</div>

			<div class="form-group">
				<label>{{ t('moviedb', 'Default TMDB Language') }}</label>
				<NcSelect v-model="selectedLanguage"
					:options="languageOptions"
					:placeholder="t('moviedb', 'Select language')" />
				<p class="hint">
					{{ t('moviedb', 'Language used for fetching movie metadata from TMDB (title, description, genres).') }}
				</p>
				<p class="hint hint-note">
					{{ t('moviedb', 'Note: Movie metadata is stored when you add a movie. Changing this setting only affects newly added movies - existing movies will keep their original language.') }}
				</p>
			</div>

			<NcButton type="primary" :disabled="saving" @click="saveSettings">
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
				<div v-for="platform in customPlatforms"
					:key="platform.id"
					class="platform-item">
					<span>{{ platform.name }}</span>
					<NcButton type="error" @click="deletePlatform(platform.id)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<div class="add-platform">
				<NcTextField v-model="newPlatformName"
					:placeholder="t('moviedb', 'Platform name')" />
				<NcButton :disabled="!newPlatformName" @click="addPlatform">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('moviedb', 'Add Platform') }}
				</NcButton>
			</div>
		</div>

		<!-- Delete Platform Confirmation Dialog -->
		<NcDialog :open="showDeletePlatformDialog"
			:name="t('moviedb', 'Delete Platform')"
			@update:open="showDeletePlatformDialog = $event">
			<p>{{ t('moviedb', 'Delete this platform?') }}</p>
			<template #actions>
				<NcButton @click="showDeletePlatformDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="confirmDeletePlatform">
					{{ t('moviedb', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Remove API Key Confirmation Dialog -->
		<NcDialog :open="showRemoveApiKeyDialog"
			:name="t('moviedb', 'Remove API Key')"
			@update:open="showRemoveApiKeyDialog = $event">
			<p>{{ t('moviedb', 'Remove API key?') }}</p>
			<template #actions>
				<NcButton @click="showRemoveApiKeyDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="confirmRemoveApiKey">
					{{ t('moviedb', 'Remove') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcTextField, NcSelect, NcButton, NcDialog } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { getTmdbLanguageOptions } from '../constants.js'
import { useSettingsStore } from '../stores/settings.js'
import { usePlatformsStore } from '../stores/platforms.js'

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
		Plus,
	},
	setup() {
		const settingsStore = useSettingsStore()
		const platformsStore = usePlatformsStore()
		return { settingsStore, platformsStore }
	},
	data() {
		return {
			tmdbApiKey: '',
			showApiKey: false,
			selectedLanguage: null,
			languageOptions: getTmdbLanguageOptions(),
			newPlatformName: '',
			saving: false,
			showDeletePlatformDialog: false,
			showRemoveApiKeyDialog: false,
			pendingDeletePlatformId: null,
		}
	},
	computed: {
		customPlatforms() {
			return this.platformsStore.customPlatforms
		},
		hasApiKey() {
			return this.settingsStore.hasApiKey
		},
	},
	mounted() {
		// Set initial language from store
		const defaultLang = this.settingsStore.defaultLanguage
		this.selectedLanguage = this.languageOptions.find(l => l.id === defaultLang) || this.languageOptions[0]
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
			} catch (error) {
				showError(t('moviedb', 'Failed to save settings. Please try again.'))
			} finally {
				this.saving = false
			}
		},
		async addPlatform() {
			if (!this.newPlatformName) return

			try {
				await this.platformsStore.create({
					name: this.newPlatformName,
				})
				showSuccess(t('moviedb', 'Platform created successfully.'))
				this.newPlatformName = ''
			} catch (error) {
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
			} catch (error) {
				showError(t('moviedb', 'Failed to delete platform. Please try again.'))
			} finally {
				this.showDeletePlatformDialog = false
				this.pendingDeletePlatformId = null
			}
		},
		async removeApiKey() {
			this.showRemoveApiKeyDialog = true
		},
		async confirmRemoveApiKey() {
			try {
				await this.settingsStore.update({ tmdbApiKey: '' })
				showSuccess(t('moviedb', 'API key removed successfully.'))
			} catch (error) {
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
</style>
