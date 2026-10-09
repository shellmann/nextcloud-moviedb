<template>
	<NcSettingsSection
		:name="t('moviedb', 'Instance-wide TMDB API key')"
		:description="t('moviedb', 'Users who have not set their own TMDB API key use this one. A personal key always takes precedence.')">
		<NcNoteCard type="info">
			<p>{{ t('moviedb', 'TMDB requests from all these users are sent with this key. Make sure this use is covered by the TMDB API terms of use.') }}</p>
			<p class="note-links">
				<a href="https://www.themoviedb.org/api-terms-of-use" target="_blank" rel="noopener noreferrer">{{ t('moviedb', 'Read the TMDB API terms of use') }}</a>
				·
				<a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener noreferrer">{{ t('moviedb', 'Get your API key here') }}</a>
			</p>
		</NcNoteCard>

		<div class="api-key-status">
			<span v-if="hasInstanceApiKey" class="status-indicator status-saved">{{ t('moviedb', 'API key configured') }}</span>
			<span v-else class="status-indicator status-missing">{{ t('moviedb', 'No API key') }}</span>
		</div>

		<div class="api-key-form">
			<NcPasswordField
				v-model="tmdbApiKey"
				class="api-key-input"
				:label="t('moviedb', 'TMDB API Key (Read Access Token)')"
				:placeholder="hasInstanceApiKey ? t('moviedb', 'Enter new key to update') : t('moviedb', 'Enter your TMDB API key')" />
			<NcButton
				variant="primary"
				:disabled="saving || !tmdbApiKey"
				@click="saveApiKey">
				{{ t('moviedb', 'Save') }}
			</NcButton>
			<NcButton
				v-if="hasInstanceApiKey"
				variant="error"
				:disabled="saving"
				@click="showRemoveDialog = true">
				{{ t('moviedb', 'Remove API Key') }}
			</NcButton>
		</div>

		<NcDialog
			:open="showRemoveDialog"
			:name="t('moviedb', 'Remove API Key')"
			@update:open="showRemoveDialog = $event">
			<p>{{ t('moviedb', 'Users without their own key will no longer be able to search TMDB.') }}</p>
			<template #actions>
				<NcButton @click="showRemoveDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="confirmRemoveApiKey">
					{{ t('moviedb', 'Remove') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcSettingsSection>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
// One path per component: importing from '@nextcloud/vue' would put the whole
// library into the admin bundle (3.8 MB instead of 0.9 MB)
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import api from '../services/api.js'
import { tmdbApiKeyErrorMessage } from '../utils/tmdbApiKey.js'

/**
 * Admin form for the instance-wide TMDB API key. The server only ever tells
 * us whether a key is set; the key itself never comes back.
 */
export default {
	name: 'AdminSettings',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcPasswordField,
		NcSettingsSection,
	},

	data() {
		const state = loadState('moviedb', 'admin-settings', {})
		return {
			hasInstanceApiKey: Boolean(state.hasInstanceApiKey),
			tmdbApiKey: '',
			saving: false,
			showRemoveDialog: false,
		}
	},

	methods: {
		async saveApiKey() {
			this.saving = true
			try {
				const response = await api.updateAdminSettings({ tmdbApiKey: this.tmdbApiKey })
				this.hasInstanceApiKey = response.data.hasInstanceApiKey
				this.tmdbApiKey = '' // Clear the field after save
				showSuccess(t('moviedb', 'Settings saved successfully.'))
			} catch (error) {
				showError(tmdbApiKeyErrorMessage(error) ?? t('moviedb', 'Failed to save settings. Please try again.'))
			} finally {
				this.saving = false
			}
		},

		async confirmRemoveApiKey() {
			this.saving = true
			try {
				const response = await api.updateAdminSettings({ tmdbApiKey: '' })
				this.hasInstanceApiKey = response.data.hasInstanceApiKey
				showSuccess(t('moviedb', 'API key removed successfully.'))
			} catch {
				showError(t('moviedb', 'Failed to remove API key. Please try again.'))
			} finally {
				this.saving = false
				this.showRemoveDialog = false
			}
		},
	},
}
</script>

<style scoped>
.note-links a {
	text-decoration: underline;
}

.api-key-status {
	margin: 12px 0 8px;
}

.status-indicator {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 12px;
	font-weight: bold;
}

/* Each *-text color is the readable text color for its background, in
   light and dark theme alike */
.status-saved {
	background: var(--color-success);
	color: var(--color-success-text);
}

.status-missing {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.api-key-form {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
	max-width: 700px;
}

.api-key-input {
	flex: 1 1 300px;
}
</style>
