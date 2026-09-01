<template>
	<div class="libraries">
		<div class="page-header">
			<h2>{{ t('moviedb', 'Libraries') }}</h2>
		</div>

		<div class="settings-section">
			<h3>{{ t('moviedb', 'Your Libraries') }}</h3>
			<p class="section-description">
				{{ t('moviedb', 'Libraries let you share your movie and TV collection with other Nextcloud users.') }}
			</p>

			<div v-if="loading" class="loading-inline">
				<NcLoadingIcon :size="24" />
			</div>

			<div v-else class="library-list">
				<div v-for="lib in libraries"
					:key="lib.id"
					class="library-item"
					:class="{ 'library-item--active': lib.id === activeLibraryId }">
					<div class="library-item__info">
						<span class="library-name">
							{{ lib.isPersonal ? t('moviedb', 'Personal') : lib.name }}
						</span>
						<span class="library-role" :class="'role-' + lib.role">
							{{ roleLabel(lib.role) }}
						</span>
						<span v-if="lib.isPersonal" class="library-personal-badge">
							{{ t('moviedb', 'Private') }}
						</span>
						<span v-if="lib.id === activeLibraryId" class="library-active-badge">
							{{ t('moviedb', 'Active') }}
						</span>
					</div>
					<div class="library-item__actions">
						<NcButton v-if="lib.id !== activeLibraryId"
							@click="switchTo(lib.id)">
							{{ t('moviedb', 'Switch') }}
						</NcButton>
						<NcButton v-if="lib.role === 'owner' && !lib.isPersonal"
							:aria-label="t('moviedb', 'Rename library')"
							:title="t('moviedb', 'Rename library')"
							@click="openRenameDialog(lib)">
							<template #icon>
								<Pencil :size="16" />
							</template>
						</NcButton>
						<NcButton v-if="!lib.isPersonal"
							:aria-label="t('moviedb', 'Members')"
							:title="t('moviedb', 'Members')"
							@click="openMembersDialog(lib)">
							<template #icon>
								<AccountMultiple :size="16" />
							</template>
						</NcButton>
						<NcButton v-if="lib.role !== 'owner' && !lib.isPersonal"
							type="error"
							:aria-label="t('moviedb', 'Leave library')"
							:title="t('moviedb', 'Leave library')"
							@click="confirmLeave(lib)">
							<template #icon>
								<ExitToApp :size="16" />
							</template>
						</NcButton>
						<NcButton v-if="lib.role === 'owner' && !lib.isPersonal"
							type="error"
							:aria-label="t('moviedb', 'Delete library')"
							:title="t('moviedb', 'Delete library')"
							@click="confirmDelete(lib)">
							<template #icon>
								<Delete :size="16" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>

			<div class="create-library">
				<NcTextField v-model="newLibraryName"
					:placeholder="t('moviedb', 'Library name')" />
				<NcButton :disabled="!newLibraryName.trim() || creating"
					type="primary"
					@click="createLibrary">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('moviedb', 'Create Library') }}
				</NcButton>
			</div>
		</div>

		<!-- Rename Dialog -->
		<NcDialog :open="showRenameDialog"
			:name="t('moviedb', 'Rename Library')"
			@update:open="showRenameDialog = $event">
			<NcTextField v-model="renameValue"
				:placeholder="t('moviedb', 'Library name')" />
			<template #actions>
				<NcButton @click="showRenameDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="!renameValue.trim()"
					@click="doRename">
					{{ t('moviedb', 'Save') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Delete Confirmation Dialog -->
		<NcDialog :open="showDeleteDialog"
			:name="t('moviedb', 'Delete Library')"
			@update:open="showDeleteDialog = $event">
			<p>{{ t('moviedb', 'Delete this library? All its movies, TV shows, and watchlist entries will be permanently removed.') }}</p>
			<template #actions>
				<NcButton @click="showDeleteDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="doDelete">
					{{ t('moviedb', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Members Dialog -->
		<NcDialog :open="showMembersDialog"
			:name="membersDialogTitle"
			size="normal"
			@update:open="onMembersDialogToggle">
			<div class="members-dialog">
				<div v-if="membersLoading" class="loading-inline">
					<NcLoadingIcon :size="24" />
				</div>
				<div v-else>
					<div v-if="members.length" class="member-list">
						<div v-for="member in members"
							:key="member.userId"
							class="member-item">
							<span class="member-name">{{ member.displayName || member.userId }}</span>
							<span class="member-role" :class="'role-' + member.role">{{ roleLabel(member.role) }}</span>
							<NcButton v-if="canManageMembers && !member.isOwner"
								type="error"
								:aria-label="t('moviedb', 'Remove member')"
								@click="confirmRemoveMember(member)">
								<template #icon>
									<Delete :size="16" />
								</template>
							</NcButton>
						</div>
					</div>
					<p v-else class="no-members">
						{{ t('moviedb', 'No members yet.') }}
					</p>

					<div v-if="canManageMembers" class="add-member">
						<h4>{{ t('moviedb', 'Add member') }}</h4>
						<NcSelectUsers v-model="selectedSharee"
							:options="shareeOptions"
							:loading="searchLoading"
							:placeholder="t('moviedb', 'Search users...')"
							:input-label="t('moviedb', 'Search users...')"
							@search="onShareeSearch" />
						<div class="add-member-role">
							<label>
								<input v-model="newMemberCanEdit"
									type="checkbox">
								{{ t('moviedb', 'Allow editing') }}
							</label>
						</div>
						<NcButton :disabled="!selectedSharee || addingMember"
							type="primary"
							@click="addMember">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('moviedb', 'Add') }}
						</NcButton>
					</div>
				</div>
			</div>
			<template #actions>
				<NcButton @click="showMembersDialog = false">
					{{ t('moviedb', 'Close') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Remove Member Confirmation Dialog -->
		<NcDialog :open="showRemoveMemberDialog"
			:name="t('moviedb', 'Remove Member')"
			@update:open="showRemoveMemberDialog = $event">
			<p>{{ removeMemberMessage }}</p>
			<template #actions>
				<NcButton @click="showRemoveMemberDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="error" :disabled="removingMember" @click="doRemoveMember">
					{{ t('moviedb', 'Remove') }}
				</NcButton>
			</template>
		</NcDialog>
		<!-- Leave Library Confirmation Dialog -->
		<NcDialog :open="showLeaveDialog"
			:name="t('moviedb', 'Leave Library')"
			@update:open="showLeaveDialog = $event">
			<p>{{ leaveMessage }}</p>
			<template #actions>
				<NcButton @click="showLeaveDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="error" :disabled="leaving" @click="doLeave">
					{{ t('moviedb', 'Leave') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcDialog, NcLoadingIcon, NcSelectUsers } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import ExitToApp from 'vue-material-design-icons/ExitToApp.vue'
import { useLibrariesStore } from '../stores/libraries.js'
import { debounce } from '../utils/debounce.js'

export default {
	name: 'Libraries',
	components: {
		NcButton,
		NcTextField,
		NcDialog,
		NcLoadingIcon,
		NcSelectUsers,
		Plus,
		Delete,
		Pencil,
		AccountMultiple,
		ExitToApp,
	},
	setup() {
		const librariesStore = useLibrariesStore()
		return { librariesStore }
	},
	data() {
		return {
			newLibraryName: '',
			creating: false,
			// Rename
			showRenameDialog: false,
			renameTarget: null,
			renameValue: '',
			// Delete
			showDeleteDialog: false,
			deleteTarget: null,
			// Members
			showMembersDialog: false,
			membersTarget: null,
			selectedSharee: null,
			shareeOptions: [],
			searchLoading: false,
			newMemberCanEdit: false,
			addingMember: false,
			// Remove-member confirmation
			showRemoveMemberDialog: false,
			removeMemberTarget: null,
			removingMember: false,
			// Leave library confirmation
			showLeaveDialog: false,
			leaveTarget: null,
			leaving: false,
		}
	},
	computed: {
		libraries() {
			return this.librariesStore.libraries
		},
		loading() {
			return this.librariesStore.loading
		},
		activeLibraryId() {
			return this.librariesStore.activeLibraryId
		},
		members() {
			return this.librariesStore.members
		},
		membersLoading() {
			return this.librariesStore.membersLoading
		},
		membersDialogTitle() {
			if (!this.membersTarget) return t('moviedb', 'Members')
			return t('moviedb', 'Members of {name}', { name: this.membersTarget.name })
		},
		canManageMembers() {
			return this.membersTarget?.role === 'owner'
		},
		removeMemberMessage() {
			const name = this.removeMemberTarget
				? (this.removeMemberTarget.displayName || this.removeMemberTarget.userId)
				: ''
			return t('moviedb', 'Remove {name} from this library?', { name })
		},
		leaveMessage() {
			const name = this.leaveTarget ? this.leaveTarget.name : ''
			return t('moviedb', 'Leave {name}?', { name })
		},
	},
	created() {
		this.debouncedSearch = debounce(this.searchSharees, 300)
	},
	methods: {
		roleLabel(role) {
			if (role === 'owner') return t('moviedb', 'Owner')
			if (role === 'editor') return t('moviedb', 'Editor')
			return t('moviedb', 'Viewer')
		},
		switchTo(id) {
			this.librariesStore.setActive(id)
		},
		async createLibrary() {
			const name = this.newLibraryName.trim()
			if (!name) return
			this.creating = true
			const result = await this.librariesStore.create(name)
			this.creating = false
			if (result) {
				this.newLibraryName = ''
			}
		},
		openRenameDialog(lib) {
			this.renameTarget = lib
			this.renameValue = lib.name
			this.showRenameDialog = true
		},
		async doRename() {
			const name = this.renameValue.trim()
			if (!name || !this.renameTarget) return
			await this.librariesStore.rename(this.renameTarget.id, name)
			this.showRenameDialog = false
			this.renameTarget = null
		},
		confirmDelete(lib) {
			this.deleteTarget = lib
			this.showDeleteDialog = true
		},
		async doDelete() {
			if (!this.deleteTarget) return
			await this.librariesStore.remove(this.deleteTarget.id)
			this.showDeleteDialog = false
			this.deleteTarget = null
		},
		async openMembersDialog(lib) {
			this.membersTarget = lib
			this.showMembersDialog = true
			await this.librariesStore.fetchMembers(lib.id)
			this.shareeOptions = []
		},
		onMembersDialogToggle(open) {
			this.showMembersDialog = open
			if (!open) {
				this.membersTarget = null
				this.selectedSharee = null
				this.shareeOptions = []
				this.newMemberCanEdit = false
			}
		},
		onShareeSearch(query) {
			if ((query || '').length >= 1) {
				this.debouncedSearch(query)
			} else {
				this.shareeOptions = []
			}
		},
		async searchSharees(query) {
			this.searchLoading = true
			const results = await this.librariesStore.searchSharees(query)
			// Hide users who are already members (or the owner) of this library.
			const existing = new Set(this.members.map(m => m.userId))
			this.shareeOptions = results.filter(r => !existing.has(r.id))
			this.searchLoading = false
		},
		async addMember() {
			if (!this.selectedSharee || !this.membersTarget) return
			this.addingMember = true
			await this.librariesStore.addMember(
				this.membersTarget.id,
				this.selectedSharee.id,
				this.newMemberCanEdit,
			)
			this.addingMember = false
			this.selectedSharee = null
			this.newMemberCanEdit = false
			this.shareeOptions = []
		},
		confirmRemoveMember(member) {
			this.removeMemberTarget = member
			this.showRemoveMemberDialog = true
		},
		async doRemoveMember() {
			if (!this.membersTarget || !this.removeMemberTarget) return
			this.removingMember = true
			await this.librariesStore.removeMember(this.membersTarget.id, this.removeMemberTarget.userId)
			this.removingMember = false
			this.showRemoveMemberDialog = false
			this.removeMemberTarget = null
			this.shareeOptions = []
		},
		confirmLeave(lib) {
			this.leaveTarget = lib
			this.showLeaveDialog = true
		},
		async doLeave() {
			if (!this.leaveTarget) return
			this.leaving = true
			await this.librariesStore.leaveLibrary(this.leaveTarget.id)
			this.leaving = false
			this.showLeaveDialog = false
			this.leaveTarget = null
		},
	},
}
</script>

<style lang="scss" scoped>
.libraries {
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
    }
}

.library-list {
    margin-bottom: 16px;
}

.library-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    background: var(--color-background-darker);
    border-radius: 4px;
    margin-bottom: 8px;
    gap: 8px;
    flex-wrap: wrap;

    &--active {
        border-left: 3px solid var(--color-primary);
    }

    &__info {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1;
        flex-wrap: wrap;
    }

    &__actions {
        display: flex;
        align-items: center;
        gap: 4px;
    }
}

.library-name {
    font-weight: bold;
}

.library-role,
.library-personal-badge,
.library-active-badge {
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 3px;
}

.library-role {
    background: var(--color-background-dark);
    color: var(--color-text-lighter);
}

.role-owner {
    background: var(--color-primary-light, #e0f0ff);
    color: var(--color-primary-dark, #003366);
}

.library-personal-badge {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}

.library-active-badge {
    background: var(--color-success);
    color: #000;
    font-weight: bold;
}

.create-library {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;

    .button-vue {
        flex-shrink: 0;
    }
}

.loading-inline {
    display: flex;
    justify-content: center;
    padding: 16px;
}

.members-dialog {
    padding: 4px 0;
}

.member-list {
    margin-bottom: 16px;
}

.member-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    border-bottom: 1px solid var(--color-border-dark);

    .member-name {
        flex: 1;
    }

    .member-role {
        font-size: 12px;
        color: var(--color-text-lighter);
    }
}

.no-members {
    color: var(--color-text-lighter);
    font-style: italic;
    margin-bottom: 16px;
}

.add-member {
    h4 {
        margin: 12px 0 8px;
    }
}

.add-member-role {
    margin: 8px 0;

    label {
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
    }
}
</style>
