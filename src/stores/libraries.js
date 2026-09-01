import { defineStore } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import api from '../services/api.js'

const STORAGE_KEY = 'moviedb_activeLibraryId'

/**
 * Resolves once the libraries store has loaded at least once, so views that
 * mount before App.vue finishes its initial fetchLibraries() can await the
 * active library before issuing their own library-scoped fetches. Without this
 * a view fetching in its own created() hook would send no libraryId (active id
 * still null) and the backend would fall back to the personal library instead
 * of the persisted active one.
 * @type {Promise<void>}
 */
let readyPromise = null
let resolveReady = null
function makeReady() {
	readyPromise = new Promise((resolve) => { resolveReady = resolve })
}
makeReady()

/**
 * Libraries store - Manages shared library state.
 * A user has one personal library (isPersonal=true) plus any shared libraries
 * they own or are a member of. The active library drives all data views.
 */
export const useLibrariesStore = defineStore('libraries', {
	state: () => ({
		/** @type {Array<object>} All accessible libraries */
		libraries: [],
		/** @type {number | null} ID of the active library */
		activeLibraryId: null,
		/** @type {boolean} Whether a fetch is in progress */
		loading: false,
		/** @type {Array<object>} Members of the currently managed library */
		members: [],
		/** @type {boolean} Whether members are loading */
		membersLoading: false,
		/** @type {boolean} True once libraries have loaded at least once */
		loaded: false,
	}),

	getters: {
		/**
		 * The active library object, or null if not found.
		 * @param {object} state - Store state
		 * @return {object | null}
		 */
		activeLibrary: (state) => {
			if (state.activeLibraryId === null) return null
			return state.libraries.find(l => l.id === state.activeLibraryId) ?? null
		},

		/**
		 * The user's personal (private) library.
		 * @param {object} state - Store state
		 * @return {object | null}
		 */
		personalLibrary: (state) => state.libraries.find(l => l.isPersonal) ?? null,

		/**
		 * Whether the active library allows the current user to make edits.
		 * Owners and editors can edit; viewers cannot.
		 * @param {object} state - Store state
		 * @return {boolean}
		 */
		activeCanEdit: (state) => {
			if (state.activeLibraryId === null) return true
			const lib = state.libraries.find(l => l.id === state.activeLibraryId)
			if (!lib) return false
			// permissionEdit covers editor/owner, role 'owner' always can edit
			return lib.role === 'owner' || lib.permissionEdit === true
		},
	},

	actions: {
		/**
		 * Fetches all libraries the user can access, then restores or defaults
		 * the active library from localStorage.
		 * @return {Promise<void>}
		 */
		async fetchLibraries() {
			this.loading = true
			try {
				const response = await api.getLibraries()
				this.libraries = response.data.libraries

				// Restore persisted active library id, or fall back to personal
				const persisted = localStorage.getItem(STORAGE_KEY)
				const persistedId = persisted ? parseInt(persisted, 10) : null
				const personal = this.libraries.find(l => l.isPersonal)

				if (persistedId && this.libraries.some(l => l.id === persistedId)) {
					this.activeLibraryId = persistedId
				} else {
					// Fall back to personal library
					this.activeLibraryId = personal ? personal.id : null
				}
			} catch (error) {
				console.error('Failed to fetch libraries:', error)
				showError(t('moviedb', 'Failed to load libraries.'))
			} finally {
				this.loading = false
				this.loaded = true
				resolveReady()
			}
		},

		/**
		 * Resolves once libraries have loaded at least once (so the active
		 * library id is known). Views await this before their first
		 * library-scoped fetch to avoid racing App.vue's initial load.
		 * @return {Promise<void>}
		 */
		whenReady() {
			return this.loaded ? Promise.resolve() : readyPromise
		},

		/**
		 * Sets the active library by ID and persists the choice.
		 * @param {number} id - Library ID to activate
		 */
		setActive(id) {
			this.activeLibraryId = id
			localStorage.setItem(STORAGE_KEY, String(id))
		},

		/**
		 * Creates a new named library.
		 * @param {string} name - Library name
		 * @return {Promise<object | null>} The created library or null on error
		 */
		async create(name) {
			try {
				const response = await api.createLibrary({ name })
				// The backend returns the library already annotated with the
				// caller's role/permissionEdit (same shape as the list endpoint),
				// so it can be stored as-is.
				const library = response.data.library
				this.libraries.push(library)
				showSuccess(t('moviedb', 'Library created successfully.'))
				return library
			} catch (error) {
				console.error('Failed to create library:', error)
				showError(t('moviedb', 'Failed to create library. Please try again.'))
				return null
			}
		},

		/**
		 * Renames a library (owner only).
		 * @param {number} id - Library ID
		 * @param {string} name - New name
		 * @return {Promise<object | null>} The updated library or null on error
		 */
		async rename(id, name) {
			try {
				const response = await api.updateLibrary(id, { name })
				// The backend returns the library annotated with the caller's
				// role/permissionEdit (same shape as the list endpoint), so it
				// can replace the stored entry as-is without dropping to viewer.
				const updated = response.data.library
				const index = this.libraries.findIndex(l => l.id === id)
				if (index !== -1) {
					this.libraries.splice(index, 1, updated)
				}
				showSuccess(t('moviedb', 'Library renamed successfully.'))
				return updated
			} catch (error) {
				console.error('Failed to rename library:', error)
				showError(t('moviedb', 'Failed to rename library. Please try again.'))
				return null
			}
		},

		/**
		 * Deletes a library (owner only, not personal). Falls back to personal
		 * library if the deleted library was active.
		 * @param {number} id - Library ID
		 * @return {Promise<boolean>} True if deleted successfully
		 */
		async remove(id) {
			try {
				await api.deleteLibrary(id)
				this.libraries = this.libraries.filter(l => l.id !== id)

				// If the deleted library was active, fall back to personal
				if (this.activeLibraryId === id) {
					const personal = this.libraries.find(l => l.isPersonal)
					this.setActive(personal ? personal.id : null)
				}
				showSuccess(t('moviedb', 'Library deleted successfully.'))
				return true
			} catch (error) {
				console.error('Failed to delete library:', error)
				showError(t('moviedb', 'Failed to delete library. Please try again.'))
				return false
			}
		},

		/**
		 * Fetches members of a library.
		 * @param {number} id - Library ID
		 * @return {Promise<void>}
		 */
		async fetchMembers(id) {
			this.membersLoading = true
			try {
				const response = await api.getLibraryMembers(id)
				this.members = response.data.members
			} catch (error) {
				console.error('Failed to fetch members:', error)
				showError(t('moviedb', 'Failed to load library members.'))
			} finally {
				this.membersLoading = false
			}
		},

		/**
		 * Adds a member to a library (owner only).
		 * @param {number} id - Library ID
		 * @param {string} userId - Nextcloud user ID
		 * @param {boolean} canEdit - Whether member gets editor rights
		 * @return {Promise<object | null>} The created member or null on error
		 */
		async addMember(id, userId, canEdit) {
			try {
				const response = await api.addLibraryMember(id, { userId, canEdit })
				const member = response.data.member
				// Upsert: the backend replaces the permission on an existing
				// membership rather than creating a second row, so mirror that
				// here — replace the matching member instead of pushing a
				// duplicate (which would render the same user twice).
				const index = this.members.findIndex(m => m.userId === member.userId)
				if (index !== -1) {
					this.members.splice(index, 1, member)
				} else {
					this.members.push(member)
				}
				showSuccess(t('moviedb', 'Member added successfully.'))
				return member
			} catch (error) {
				console.error('Failed to add member:', error)
				showError(t('moviedb', 'Failed to add member. Please try again.'))
				return null
			}
		},

		/**
		 * Removes a member from a library (owner only).
		 * @param {number} id - Library ID
		 * @param {string} userId - Nextcloud user ID
		 * @return {Promise<boolean>} True if removed successfully
		 */
		async removeMember(id, userId) {
			try {
				await api.removeLibraryMember(id, userId)
				this.members = this.members.filter(m => m.userId !== userId)
				showSuccess(t('moviedb', 'Member removed successfully.'))
				return true
			} catch (error) {
				console.error('Failed to remove member:', error)
				showError(t('moviedb', 'Failed to remove member. Please try again.'))
				return false
			}
		},

		/**
		 * Leaves a shared library (member removes themselves).
		 * @param {number} id - Library ID
		 * @return {Promise<boolean>} True if left successfully
		 */
		async leaveLibrary(id) {
			try {
				await api.leaveLibrary(id)
				this.libraries = this.libraries.filter(l => l.id !== id)
				if (this.activeLibraryId === id) {
					const personal = this.libraries.find(l => l.isPersonal)
					this.setActive(personal ? personal.id : null)
				}
				showSuccess(t('moviedb', 'You have left the library.'))
				return true
			} catch (error) {
				console.error('Failed to leave library:', error)
				showError(t('moviedb', 'Failed to leave library. Please try again.'))
				return false
			}
		},

		/**
		 * Searches for Nextcloud users to share a library with.
		 * @param {string} query - Search term
		 * @return {Promise<Array<object>>} List of sharees [{id, label}]
		 */
		async searchSharees(query) {
			try {
				const response = await api.searchSharees(query)
				// Backend returns [{id, label}]. NcSelectUsers renders via
				// NcListItemIcon and keys its display on `displayName`, so map
				// label → displayName and expose `user` for the avatar.
				return (response.data.sharees || []).map(s => ({
					id: s.id,
					user: s.id,
					displayName: s.label || s.id,
				}))
			} catch (error) {
				console.error('Failed to search sharees:', error)
				showError(t('moviedb', 'Failed to search users.'))
				return []
			}
		},
	},
})
