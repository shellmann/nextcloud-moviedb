<template>
	<NcDialog
		:open="open"
		:name="t('moviedb', 'What\'s new in MovieDB')"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="whats-new">
			<section v-for="release in notes" :key="release.version" class="release">
				<h3 class="release-version">
					{{ t('moviedb', 'Version {version}', { version: release.version }) }}
				</h3>
				<ul>
					<li v-for="(item, i) in release.items" :key="i">
						{{ item }}
					</li>
				</ul>
			</section>
			<p class="all-changes">
				<a :href="changelogUrl" target="_blank" rel="noopener noreferrer">
					{{ t('moviedb', 'All changes (opens GitHub)') }}
				</a>
			</p>
		</div>
		<template #actions>
			<NcButton variant="primary" @click="close">
				{{ t('moviedb', 'Got it') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'
import api from '../services/api.js'
import { compareVersions, unseenNotes } from '../whatsNew/releaseNotes.js'

const CHANGELOG_URL = 'https://github.com/shellmann/nextcloud-moviedb/blob/main/CHANGELOG.md'

/**
 * Shows the highlights of every MovieDB version the user has not seen yet,
 * once, on the first visit after an update. New users see nothing (the
 * server already marks their first visit as seen).
 */
export default {
	name: 'WhatsNewDialog',
	components: {
		NcButton,
		NcDialog,
	},

	data() {
		const state = loadState('moviedb', 'whatsNew', null) || {}
		return {
			currentVersion: state.currentVersion || '',
			lastSeenVersion: state.lastSeenVersion || '',
			open: false,
			changelogUrl: CHANGELOG_URL,
		}
	},

	computed: {
		notes() {
			return unseenNotes(this.lastSeenVersion, this.currentVersion)
		},
	},

	mounted() {
		if (this.notes.length) {
			this.open = true
		} else if (this.currentVersion && this.lastSeenVersion && compareVersions(this.lastSeenVersion, this.currentVersion) < 0) {
			// An update without highlights: nothing to show, just move on.
			this.markSeen()
		}
	},

	methods: {
		t,

		onUpdateOpen(value) {
			if (!value) {
				this.close()
			}
		},

		close() {
			this.open = false
			this.markSeen()
		},

		async markSeen() {
			this.lastSeenVersion = this.currentVersion
			try {
				await api.updateSettings({ lastSeenVersion: this.currentVersion })
			} catch (error) {
				// Not worth an error toast: at worst the dialog shows once more.
				console.error('Failed to save the seen version:', error)
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.whats-new {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.release-version {
    margin: 0 0 4px;
    font-size: 16px;
}

ul {
    margin: 0;
    padding-inline-start: 20px;
    list-style: disc;

    li + li {
        margin-top: 6px;
    }
}

.all-changes {
    margin: 0;

    a {
        color: var(--color-primary-element);
        text-decoration: underline;
    }
}
</style>
