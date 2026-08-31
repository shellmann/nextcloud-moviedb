import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useLibrariesStore } from '@/stores/libraries.js'

// jsdom exposes localStorage on window but not globalThis; stub it so the store
// can access it via the bare `localStorage` identifier.
const localStorageMock = (() => {
	let store = {}
	return {
		getItem: (k) => store[k] ?? null,
		setItem: (k, v) => { store[k] = String(v) },
		removeItem: (k) => { delete store[k] },
		clear: () => { store = {} },
	}
})()
vi.stubGlobal('localStorage', localStorageMock)

vi.mock('@/services/api.js', () => ({
	default: {
		getLibraries: vi.fn(),
		createLibrary: vi.fn(),
		updateLibrary: vi.fn(),
		deleteLibrary: vi.fn(),
		getLibraryMembers: vi.fn(),
		addLibraryMember: vi.fn(),
		removeLibraryMember: vi.fn(),
		leaveLibrary: vi.fn(),
		searchSharees: vi.fn(),
	},
}))

import api from '@/services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

const personalLib = { id: 1, owner: 'alice', name: 'Personal', isPersonal: true, role: 'owner', permissionEdit: true }
const sharedLib   = { id: 2, owner: 'alice', name: 'Family', isPersonal: false, role: 'owner', permissionEdit: true }
const memberLib   = { id: 7, owner: 'bob', name: 'SmokeTestLib', isPersonal: false, role: 'editor', permissionEdit: true }

describe('Libraries Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useLibrariesStore()
		vi.clearAllMocks()
		localStorage.clear()
	})

	describe('initial state', () => {
		it('should have empty libraries array', () => {
			expect(store.libraries).toEqual([])
		})

		it('should have null active library id', () => {
			expect(store.activeLibraryId).toBeNull()
		})

		it('should have loading false', () => {
			expect(store.loading).toBe(false)
		})
	})

	describe('getters', () => {
		beforeEach(() => {
			store.libraries = [personalLib, sharedLib]
			store.activeLibraryId = 1
		})

		it('activeLibrary returns the active library object', () => {
			expect(store.activeLibrary).toEqual(personalLib)
		})

		it('activeLibrary returns null when id is null', () => {
			store.activeLibraryId = null
			expect(store.activeLibrary).toBeNull()
		})

		it('personalLibrary returns the personal library', () => {
			expect(store.personalLibrary).toEqual(personalLib)
		})

		it('personalLibrary returns null when none exists', () => {
			store.libraries = [sharedLib]
			expect(store.personalLibrary).toBeNull()
		})

		it('activeCanEdit returns true for owner', () => {
			store.activeLibraryId = 1
			expect(store.activeCanEdit).toBe(true)
		})

		it('activeCanEdit returns true for editor member', () => {
			store.libraries = [personalLib, memberLib]
			store.activeLibraryId = 7
			expect(store.activeCanEdit).toBe(true)
		})

		it('activeCanEdit returns false for viewer member', () => {
			const viewerLib = { ...memberLib, role: 'viewer', permissionEdit: false }
			store.libraries = [personalLib, viewerLib]
			store.activeLibraryId = 7
			expect(store.activeCanEdit).toBe(false)
		})

		it('activeCanEdit returns true when no active library', () => {
			store.activeLibraryId = null
			expect(store.activeCanEdit).toBe(true)
		})
	})

	describe('fetchLibraries', () => {
		it('loads libraries and defaults to personal', async () => {
			api.getLibraries.mockResolvedValue({ data: { libraries: [personalLib, sharedLib] } })

			await store.fetchLibraries()

			expect(store.libraries).toHaveLength(2)
			expect(store.activeLibraryId).toBe(1)
			expect(store.loading).toBe(false)
			expect(store.loaded).toBe(true)
		})

		it('restores persisted active library from localStorage', async () => {
			localStorage.setItem('moviedb_activeLibraryId', '2')
			api.getLibraries.mockResolvedValue({ data: { libraries: [personalLib, sharedLib] } })

			await store.fetchLibraries()

			expect(store.activeLibraryId).toBe(2)
		})

		it('falls back to personal if persisted id is no longer accessible', async () => {
			localStorage.setItem('moviedb_activeLibraryId', '999')
			api.getLibraries.mockResolvedValue({ data: { libraries: [personalLib] } })

			await store.fetchLibraries()

			expect(store.activeLibraryId).toBe(1)
		})

		it('shows error toast on failure', async () => {
			api.getLibraries.mockRejectedValue(new Error('Network error'))

			await store.fetchLibraries()

			expect(showError).toHaveBeenCalledOnce()
			expect(store.loaded).toBe(true)
		})
	})

	describe('setActive', () => {
		it('updates activeLibraryId and persists to localStorage', () => {
			store.setActive(5)
			expect(store.activeLibraryId).toBe(5)
			expect(localStorage.getItem('moviedb_activeLibraryId')).toBe('5')
		})
	})

	describe('create', () => {
		it('adds library to state and shows success', async () => {
			store.libraries = [personalLib]
			api.createLibrary.mockResolvedValue({ data: { library: sharedLib } })

			const result = await store.create('Family')

			expect(store.libraries).toHaveLength(2)
			expect(result).toEqual(sharedLib)
			expect(showSuccess).toHaveBeenCalledOnce()
		})

		it('shows error and returns null on failure', async () => {
			api.createLibrary.mockRejectedValue(new Error('fail'))

			const result = await store.create('Bad Name')

			expect(result).toBeNull()
			expect(showError).toHaveBeenCalledOnce()
		})
	})

	describe('rename', () => {
		it('replaces library in state and shows success', async () => {
			store.libraries = [personalLib, sharedLib]
			const renamed = { ...sharedLib, name: 'Renamed Family' }
			api.updateLibrary.mockResolvedValue({ data: { library: renamed } })

			const result = await store.rename(2, 'Renamed Family')

			expect(store.libraries.find(l => l.id === 2).name).toBe('Renamed Family')
			expect(result).toEqual(renamed)
			expect(showSuccess).toHaveBeenCalledOnce()
		})
	})

	describe('remove', () => {
		it('removes library and shows success', async () => {
			store.libraries = [personalLib, sharedLib]
			store.activeLibraryId = 1
			api.deleteLibrary.mockResolvedValue({ data: { success: true } })

			await store.remove(2)

			expect(store.libraries).toHaveLength(1)
			expect(store.libraries[0].id).toBe(1)
			expect(showSuccess).toHaveBeenCalledOnce()
		})

		it('falls back to personal library when active library is deleted', async () => {
			store.libraries = [personalLib, sharedLib]
			store.activeLibraryId = 2
			api.deleteLibrary.mockResolvedValue({ data: { success: true } })

			await store.remove(2)

			expect(store.activeLibraryId).toBe(1)
		})
	})

	describe('leaveLibrary', () => {
		it('removes library from state and shows success toast', async () => {
			store.libraries = [personalLib, memberLib]
			store.activeLibraryId = 1
			api.leaveLibrary.mockResolvedValue({ data: { success: true } })

			const result = await store.leaveLibrary(7)

			expect(result).toBe(true)
			expect(store.libraries.find(l => l.id === 7)).toBeUndefined()
			expect(showSuccess).toHaveBeenCalledWith('You have left the library.')
		})

		it('falls back to personal when the left library was active', async () => {
			store.libraries = [personalLib, memberLib]
			store.activeLibraryId = 7
			api.leaveLibrary.mockResolvedValue({ data: { success: true } })

			await store.leaveLibrary(7)

			expect(store.activeLibraryId).toBe(1)
		})

		it('shows error and returns false on API failure', async () => {
			store.libraries = [personalLib, memberLib]
			api.leaveLibrary.mockRejectedValue(new Error('403'))

			const result = await store.leaveLibrary(7)

			expect(result).toBe(false)
			expect(store.libraries).toHaveLength(2)
			expect(showError).toHaveBeenCalledOnce()
		})

		it('calls the correct API endpoint', async () => {
			store.libraries = [personalLib, memberLib]
			api.leaveLibrary.mockResolvedValue({ data: { success: true } })

			await store.leaveLibrary(7)

			expect(api.leaveLibrary).toHaveBeenCalledWith(7)
		})
	})

	describe('addMember / removeMember', () => {
		const member = { id: 10, userId: 'charlie', permissionEdit: false, displayName: 'Charlie', role: 'viewer', isOwner: false }

		it('addMember appends new member to state', async () => {
			store.members = []
			api.addLibraryMember.mockResolvedValue({ data: { member } })

			await store.addMember(5, 'charlie', false)

			expect(store.members).toHaveLength(1)
			expect(store.members[0].userId).toBe('charlie')
		})

		it('addMember upserts when member already exists', async () => {
			const updatedMember = { ...member, permissionEdit: true, role: 'editor' }
			store.members = [member]
			api.addLibraryMember.mockResolvedValue({ data: { member: updatedMember } })

			await store.addMember(5, 'charlie', true)

			expect(store.members).toHaveLength(1)
			expect(store.members[0].permissionEdit).toBe(true)
		})

		it('removeMember removes member from state', async () => {
			store.members = [member]
			api.removeLibraryMember.mockResolvedValue({ data: { success: true } })

			await store.removeMember(5, 'charlie')

			expect(store.members).toHaveLength(0)
			expect(showSuccess).toHaveBeenCalledOnce()
		})
	})
})
