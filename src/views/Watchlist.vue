<template>
	<div class="watchlist">
		<div class="list-header">
			<h2>{{ t('moviedb', 'Watchlist') }}</h2>
			<div class="header-actions">
				<NcButton v-if="activeCanEdit"
					type="primary"
					@click="$router.push({ name: 'add-to-watchlist' })">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('moviedb', 'Add') }}
				</NcButton>
				<NcButton :aria-label="t('moviedb', 'Pick Random')"
					:title="t('moviedb', 'Pick Random')"
					@click="pickRandom">
					<template #icon>
						<DiceMultiple :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<div class="filters">
			<NcSelect v-model="selectedType"
				:options="typeOptions"
				:placeholder="t('moviedb', 'Type')"
				:aria-label="t('moviedb', 'Filter by type')"
				:clearable="false"
				@update:modelValue="onTypeChange" />
			<NcSelect v-model="selectedSort"
				:options="sortOptions"
				:placeholder="t('moviedb', 'Sort by')"
				:aria-label="t('moviedb', 'Sort by')"
				@update:modelValue="onSortChange" />
		</div>

		<div v-if="loading" class="loading">
			<NcLoadingIcon :size="44" />
		</div>

		<div v-else-if="items.length" class="watchlist-grid">
			<div v-for="item in items"
				:key="item.id"
				:ref="'item-' + item.id"
				class="watchlist-item"
				:class="{ highlighted: highlightedId === item.id }">
				<div class="item-poster">
					<img v-if="item.posterPath"
						:src="getPosterUrl(item.posterPath)"
						:alt="item.title">
					<div v-else class="no-poster">
						{{ t('moviedb', 'No poster') }}
					</div>
				</div>
				<div class="item-info">
					<div class="item-header">
						<h3>{{ item.title }}</h3>
						<span class="type-badge" :class="isSeries(item) ? 'type-tv' : 'type-movie'">
							{{ isSeries(item) ? t('moviedb', 'TV') : t('moviedb', 'Movie') }}
						</span>
						<span v-if="item.priority > 0" class="priority-badge" :class="getPriorityColor(item.priority)">
							{{ getPriorityLabel(item.priority) }}
						</span>
					</div>
					<p v-if="item.releaseDate" class="release-date">
						{{ item.releaseDate.substring(0, 4) }}
						<span v-if="getGenreNames(item).length" class="genre-tags">
							<span v-for="genre in getGenreNames(item)" :key="genre" class="genre-tag">{{ genre }}</span>
						</span>
					</p>
					<p v-if="item.notes" class="notes">
						{{ item.notes }}
					</p>
					<div class="item-actions">
						<NcButton v-if="activeCanEdit"
							type="primary"
							@click="openWatchedModal(item)">
							<template #icon>
								<Check :size="20" />
							</template>
							{{ isSeries(item) ? t('moviedb', 'Add to TV Shows') : t('moviedb', 'Mark as Watched') }}
						</NcButton>
						<NcButton v-if="activeCanEdit"
							:aria-label="t('moviedb', 'Edit')"
							@click="openEditModal(item)">
							<template #icon>
								<Pencil :size="20" />
							</template>
						</NcButton>
						<NcButton v-if="activeCanEdit"
							:aria-label="t('moviedb', 'Delete')"
							type="error"
							@click="removeFromWatchlist(item.id)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<NcEmptyContent v-else :name="t('moviedb', 'Your watchlist is empty')">
			<template #icon>
				<PlaylistPlay :size="64" />
			</template>
			<template #action>
				<NcButton v-if="activeCanEdit" @click="$router.push({ name: 'add-to-watchlist' })">
					{{ t('moviedb', 'Search for something to add') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<!-- Mark as Watched Modal -->
		<NcModal v-if="showWatchedModal" @close="showWatchedModal = false">
			<div class="watched-modal">
				<h3>{{ t('moviedb', 'Mark as Watched') }}: "{{ selectedItem?.title }}"</h3>
				<div class="form-group">
					<label>{{ t('moviedb', 'Platform') }}</label>
					<NcSelect v-model="watchedData.platform"
						:options="platformOptions"
						:placeholder="t('moviedb', 'Select platform')" />
				</div>
				<div class="form-group">
					<label>{{ t('moviedb', 'Language Watched') }}</label>
					<NcSelect v-model="watchedData.language"
						:options="languageOptions"
						:placeholder="t('moviedb', 'Select language')" />
				</div>
				<div class="form-group">
					<label>{{ t('moviedb', 'Date Watched') }}</label>
					<NcTextField v-model="watchedData.dateWatched" type="date" />
				</div>
				<div class="form-group">
					<label>{{ t('moviedb', 'Rating') }}</label>
					<NcSelect v-model="watchedData.rating"
						:options="ratingOptions"
						:placeholder="t('moviedb', 'Select rating')" />
				</div>
				<div class="modal-actions">
					<NcButton @click="showWatchedModal = false">
						{{ t('moviedb', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :disabled="saving" @click="confirmWatched">
						{{ t('moviedb', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Edit Watchlist Item Modal -->
		<NcModal v-if="showEditModal" @close="showEditModal = false">
			<div class="edit-modal">
				<h3>{{ t('moviedb', 'Edit') }}: "{{ selectedItem?.title }}"</h3>
				<div class="form-group">
					<label>{{ t('moviedb', 'Priority') }}</label>
					<NcSelect v-model="editData.priority"
						:options="priorityOptions"
						:placeholder="t('moviedb', 'Select priority')" />
				</div>
				<div class="form-group">
					<label>{{ t('moviedb', 'Notes') }}</label>
					<textarea v-model="editData.notes"
						rows="3"
						:placeholder="t('moviedb', 'Why do you want to watch this?')" />
				</div>
				<div class="modal-actions">
					<NcButton @click="showEditModal = false">
						{{ t('moviedb', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :disabled="saving" @click="saveEdit">
						{{ t('moviedb', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Remove from Watchlist Confirmation Dialog -->
		<NcDialog :open="showRemoveDialog"
			:name="t('moviedb', 'Remove from Watchlist')"
			@update:open="showRemoveDialog = $event">
			<p>{{ t('moviedb', 'Remove from watchlist?') }}</p>
			<template #actions>
				<NcButton @click="showRemoveDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="confirmRemove">
					{{ t('moviedb', 'Remove') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcModal, NcSelect, NcTextField, NcDialog } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DiceMultiple from 'vue-material-design-icons/DiceMultiple.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import PlaylistPlay from 'vue-material-design-icons/PlaylistPlay.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { LANGUAGE_OPTIONS, getGenreOptions, getRatingOptions, getPriorityOptions, getPriorityLabel, getPriorityColor, MEDIA_TYPE } from '../constants.js'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { useWatchlistStore } from '../stores/watchlist.js'
import { usePlatformsStore } from '../stores/platforms.js'
import { useSettingsStore } from '../stores/settings.js'
import { useLibrariesStore } from '../stores/libraries.js'

export default {
	name: 'Watchlist',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcModal,
		NcSelect,
		NcTextField,
		NcDialog,
		Check,
		Delete,
		DiceMultiple,
		Pencil,
		PlaylistPlay,
		Plus,
	},
	setup() {
		const watchlistStore = useWatchlistStore()
		const platformsStore = usePlatformsStore()
		const settingsStore = useSettingsStore()
		const librariesStore = useLibrariesStore()
		return { watchlistStore, platformsStore, settingsStore, librariesStore }
	},
	data() {
		return {
			showWatchedModal: false,
			showEditModal: false,
			showRemoveDialog: false,
			selectedItem: null,
			pendingRemoveId: null,
			highlightedId: null,
			selectedSort: null,
			selectedType: null,
			watchedData: {
				platform: null,
				language: null,
				dateWatched: new Date().toISOString().split('T')[0],
				rating: null,
			},
			editData: {
				priority: null,
				notes: '',
			},
			languageOptions: LANGUAGE_OPTIONS,
			ratingOptions: getRatingOptions(),
			priorityOptions: getPriorityOptions(),
			sortOptions: [
				{ id: 'priority', label: t('moviedb', 'Priority') },
				{ id: 'added_at', label: t('moviedb', 'Date Added') },
				{ id: 'title', label: t('moviedb', 'Title') },
			],
			typeOptions: [
				{ id: 'all', label: t('moviedb', 'All') },
				{ id: 'movie', label: t('moviedb', 'Movies') },
				{ id: 'series', label: t('moviedb', 'TV Shows') },
			],
			saving: false,
		}
	},
	computed: {
		items() {
			return this.watchlistStore.filteredItems
		},
		loading() {
			return this.watchlistStore.loading
		},
		platforms() {
			return this.platformsStore.platforms
		},
		platformOptions() {
			return this.platforms.map(p => ({ id: p.id, label: p.name }))
		},
		activeCanEdit() {
			return this.librariesStore.activeCanEdit
		},
	},
	async created() {
		this.selectedSort = this.sortOptions[0]
		this.selectedType = this.typeOptions[0]
		this.watchlistStore.resetSort()
		this.watchlistStore.setTypeFilter('all')
		// Wait for libraries so the active library id is known before fetching.
		await this.librariesStore.whenReady()
		this.watchlistStore.fetchAll()
	},
	methods: {
		getPosterUrl,
		getPriorityLabel,
		getPriorityColor,
		isSeries(item) {
			return (item.mediaType || 'movie') === MEDIA_TYPE.SERIES
		},
		onSortChange(selected) {
			if (selected) {
				this.watchlistStore.setSort(selected.id, 'DESC')
			}
		},
		onTypeChange(selected) {
			this.watchlistStore.setTypeFilter(selected ? selected.id : 'all')
		},
		pickRandom() {
			if (!this.items.length) return
			const randomIndex = Math.floor(Math.random() * this.items.length)
			const item = this.items[randomIndex]
			this.highlightedId = item.id
			this.$nextTick(() => {
				const el = this.$refs['item-' + item.id]
				if (el && el[0]) {
					el[0].scrollIntoView({ behavior: 'smooth', block: 'center' })
				}
			})
			setTimeout(() => {
				this.highlightedId = null
			}, 4000)
		},
		getGenreNames(item) {
			const genreIds = item.genreIds
			if (!genreIds) return []
			const ids = Array.isArray(genreIds) ? genreIds : JSON.parse(genreIds || '[]')
			const options = getGenreOptions(this.isSeries(item) ? MEDIA_TYPE.SERIES : MEDIA_TYPE.MOVIE)
			return ids
				.map(id => options.find(g => g.id === id)?.label)
				.filter(Boolean)
				.slice(0, 2)
		},
		async openWatchedModal(item) {
			// Series don't have a single watch event — importing the show tracks it
			// at 0% and the user marks episodes/seasons afterward. Skip the modal.
			if (this.isSeries(item)) {
				const result = await this.watchlistStore.moveToWatched(item.id, {
					language: this.settingsStore.defaultLanguage || 'en-US',
				})
				if (result?.series) {
					this.$router.push({ name: 'series-detail', params: { id: String(result.series.id) } })
				}
				return
			}
			this.selectedItem = item
			this.watchedData = {
				platform: null,
				language: this.languageOptions[0], // Default to English (first in list)
				dateWatched: new Date().toISOString().split('T')[0],
				rating: null,
			}
			this.showWatchedModal = true
		},
		async confirmWatched() {
			this.saving = true
			const result = await this.watchlistStore.moveToWatched(this.selectedItem.id, {
				platformId: this.watchedData.platform?.id,
				languageWatched: this.watchedData.language?.id,
				dateWatched: this.watchedData.dateWatched,
				rating: this.watchedData.rating?.id,
			})
			if (result) {
				this.showWatchedModal = false
			}
			this.saving = false
		},
		async removeFromWatchlist(id) {
			this.pendingRemoveId = id
			this.showRemoveDialog = true
		},
		async confirmRemove() {
			await this.watchlistStore.delete(this.pendingRemoveId)
			this.showRemoveDialog = false
			this.pendingRemoveId = null
		},
		openEditModal(item) {
			this.selectedItem = item
			this.editData = {
				priority: this.priorityOptions.find(p => p.id === item.priority) || this.priorityOptions[0],
				notes: item.notes || '',
			}
			this.showEditModal = true
		},
		async saveEdit() {
			if (this.saving) return

			this.saving = true
			const item = await this.watchlistStore.update(this.selectedItem.id, {
				priority: this.editData.priority?.id ?? 0,
				notes: this.editData.notes,
			})
			if (item) {
				this.showEditModal = false
			}
			this.saving = false
		},
	},
}
</script>

<style lang="scss" scoped>
.watchlist {
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
}

.list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;

    h2 {
        margin: 0;
        font-size: 24px;
    }

    .header-actions {
        display: flex;
        gap: 8px;
        align-items: center;
    }
}

.filters {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.loading {
    display: flex;
    justify-content: center;
    padding: 40px;
}

.watchlist-grid {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.watchlist-item {
    display: flex;
    gap: 16px;
    background: var(--color-background-dark);
    border-radius: 8px;
    padding: 16px;
    transition: box-shadow 0.3s, background-color 0.3s;

    &.highlighted {
        animation: highlight-pulse 4s ease-out;
    }

    @media (max-width: 600px) {
        flex-direction: column;
    }
}

@keyframes highlight-pulse {
    0% {
        box-shadow: 0 0 0 3px var(--color-primary);
        background-color: var(--color-primary-element-light);
    }
    100% {
        box-shadow: 0 0 0 0 transparent;
        background-color: var(--color-background-dark);
    }
}

.item-poster {
    flex-shrink: 0;
    width: 100px;

    img {
        width: 100%;
        border-radius: 4px;
    }

    .no-poster {
        width: 100%;
        aspect-ratio: 2/3;
        background: var(--color-background-darker);
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        color: var(--color-text-lighter);
    }
}

.item-info {
    flex: 1;

    .item-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 4px;
    }

    h3 {
        margin: 0;
        font-size: 1.1em;
    }

    .type-badge {
        font-size: 10px;
        padding: 2px 8px;
        border-radius: 10px;
        font-weight: bold;
        text-transform: uppercase;

        &.type-movie {
            background: var(--color-primary-element-light);
            color: var(--color-primary-element-light-text);
        }

        &.type-tv {
            background: var(--color-success);
            color: var(--color-success-text, #fff);
        }
    }

    .priority-badge {
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 10px;
        font-weight: bold;
        text-transform: uppercase;
        background: var(--color-background-darker);
        color: var(--color-text-lighter);
        &.warning {
            background: var(--color-warning);
            color: var(--color-warning-text);
        }

        &.error {
            background: var(--color-error);
            color: var(--color-error-text);
        }
    }

    .release-date {
        color: var(--color-text-lighter);
        margin: 0 0 8px;
    }

    .genre-tags {
        margin-left: 8px;
    }

    .genre-tag {
        display: inline-block;
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 8px;
        background: var(--color-primary-element-light);
        color: var(--color-primary-element-light-text);
        margin-left: 4px;
    }

    .notes {
        font-style: italic;
        margin: 0 0 12px;
    }
}

.item-actions {
    display: flex;
    gap: 8px;
}

.watched-modal {
    padding: 20px;
    min-width: 350px;

    h3 {
        margin: 0 0 20px;
    }
}

.edit-modal {
    padding: 20px;
    min-width: 350px;

    h3 {
        margin: 0 0 20px;
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
}

.form-group {
    margin-bottom: 16px;

    label {
        display: block;
        margin-bottom: 4px;
        font-weight: bold;
    }
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 20px;
}
</style>
