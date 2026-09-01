<template>
	<div class="series-list">
		<div class="list-header">
			<h2>
				{{ t('moviedb', 'TV Shows') }}
				<span v-if="!loading" class="series-count">({{ total }})</span>
			</h2>
			<div class="header-actions">
				<NcTextField v-model="searchQuery"
					:label="t('moviedb', 'Search')"
					:placeholder="t('moviedb', 'Search TV shows...')"
					@update:modelValue="debouncedSearch" />
				<NcButton v-if="activeCanEdit"
					:aria-label="t('moviedb', 'Add TV Show')"
					:title="t('moviedb', 'Add TV Show')"
					@click="$router.push({ name: 'add-series' })">
					<template #icon>
						<Plus :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<div class="filters">
			<NcSelect v-model="selectedGenre"
				:options="genreOptions"
				:placeholder="t('moviedb', 'All genres')"
				:clearable="true"
				@update:modelValue="applyFilters" />
			<NcSelect v-model="sortBy"
				:options="sortOptions"
				:placeholder="t('moviedb', 'Sort by')"
				:aria-label="t('moviedb', 'Sort by')"
				@update:modelValue="applyFilters" />
			<NcButton :aria-label="t('moviedb', 'Toggle sort direction')"
				:title="sortDirection === 'DESC' ? t('moviedb', 'Descending') : t('moviedb', 'Ascending')"
				@click="toggleSortDirection">
				<template #icon>
					<SortDescending v-if="sortDirection === 'DESC'" :size="20" />
					<SortAscending v-else :size="20" />
				</template>
			</NcButton>
			<NcButton :type="showFavoritesOnly ? 'primary' : 'secondary'"
				@click="toggleFavorites">
				<template #icon>
					<Heart :size="20" />
				</template>
				{{ t('moviedb', 'Favorites') }}
			</NcButton>
		</div>

		<div v-if="loading" class="loading">
			<NcLoadingIcon :size="44" />
		</div>

		<div v-else-if="series.length" class="series-grid">
			<SeriesCard v-for="item in series"
				:key="item.id"
				:series="item"
				@click="goToSeries(item.id)" />
		</div>

		<NcEmptyContent v-else :name="emptyStateMessage">
			<template #icon>
				<Heart v-if="showFavoritesOnly" :size="64" />
				<Television v-else :size="64" />
			</template>
			<template #description>
				<p v-if="showFavoritesOnly">
					{{ t('moviedb', 'Mark TV shows as favorite from the series detail page.') }}
				</p>
			</template>
			<template #action>
				<NcButton v-if="!showFavoritesOnly && activeCanEdit" @click="$router.push({ name: 'add-series' })">
					{{ t('moviedb', 'Add your first TV show') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-if="totalPages > 1" class="pagination">
			<NcButton :disabled="page <= 1"
				@click="goToPage(page - 1)">
				{{ t('moviedb', 'Previous') }}
			</NcButton>
			<span class="page-info">{{ t('moviedb', 'Page {page} of {total}', { page: page, total: totalPages }) }}</span>
			<NcButton :disabled="page >= totalPages"
				@click="goToPage(page + 1)">
				{{ t('moviedb', 'Next') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcButton, NcSelect, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import Television from 'vue-material-design-icons/Television.vue'
import SortAscending from 'vue-material-design-icons/SortAscending.vue'
import SortDescending from 'vue-material-design-icons/SortDescending.vue'
import SeriesCard from '../components/SeriesCard.vue'
import { debounce } from '../utils/debounce.js'
import { useSeriesStore } from '../stores/series.js'
import { useLibrariesStore } from '../stores/libraries.js'
import { TV_GENRE_OPTIONS } from '../constants.js'

export default {
	name: 'SeriesList',
	components: {
		NcTextField,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcEmptyContent,
		Plus,
		Heart,
		Television,
		SortAscending,
		SortDescending,
		SeriesCard,
	},
	setup() {
		const seriesStore = useSeriesStore()
		const librariesStore = useLibrariesStore()
		return { seriesStore, librariesStore }
	},
	data() {
		return {
			searchQuery: '',
			selectedGenre: null,
			sortBy: null,
			sortDirection: 'DESC',
			showFavoritesOnly: false,
		}
	},
	computed: {
		series() {
			return this.seriesStore.series
		},
		loading() {
			return this.seriesStore.loading
		},
		total() {
			return this.seriesStore.total
		},
		page() {
			return this.seriesStore.page
		},
		totalPages() {
			return this.seriesStore.totalPages
		},
		genreOptions() {
			return TV_GENRE_OPTIONS
		},
		sortOptions() {
			return [
				{ id: 'date_watched', label: t('moviedb', 'Date Watched') },
				{ id: 'title', label: t('moviedb', 'Title') },
				{ id: 'rating', label: t('moviedb', 'Rating') },
				{ id: 'first_air_year', label: t('moviedb', 'First Air Year') },
			]
		},
		emptyStateMessage() {
			if (this.showFavoritesOnly) {
				return t('moviedb', 'No favorite TV shows yet')
			}
			return t('moviedb', 'No TV shows found')
		},
		activeCanEdit() {
			return this.librariesStore.activeCanEdit
		},
	},
	async created() {
		this.sortBy = this.sortOptions[0]
		this.debouncedSearch = debounce(this.applyFilters, 300)
		this.seriesStore.resetFilters()
		// Wait until libraries have loaded so the active library id is known;
		// otherwise this first fetch races App.vue's initial load and falls
		// back to the personal library instead of the active one.
		await this.librariesStore.whenReady()
		this.seriesStore.fetchAll()
	},
	methods: {
		applyFilters() {
			this.seriesStore.setFilters({
				search: this.searchQuery,
				genre: this.selectedGenre?.id || null,
				sort: this.sortBy?.id || 'date_watched',
				dir: this.sortDirection,
				favorite: this.showFavoritesOnly,
			})
		},
		toggleSortDirection() {
			this.sortDirection = this.sortDirection === 'DESC' ? 'ASC' : 'DESC'
			this.applyFilters()
		},
		toggleFavorites() {
			this.showFavoritesOnly = !this.showFavoritesOnly
			this.applyFilters()
		},
		goToPage(page) {
			this.seriesStore.setPage(page)
		},
		goToSeries(id) {
			this.$router.push({ name: 'series-detail', params: { id } })
		},
	},
}
</script>

<style lang="scss" scoped>
.series-list {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 16px;

    h2 {
        margin: 0;
        font-size: 24px;

        .series-count {
            font-size: 16px;
            font-weight: normal;
            color: var(--color-text-maxcontrast);
        }
    }

    .header-actions {
        display: flex;
        gap: 12px;
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

.series-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 20px;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 16px;
    margin-top: 32px;

    .page-info {
        color: var(--color-text-lighter);
    }
}

@media (max-width: 600px) {
    .list-header {
        h2 {
            font-size: 20px;
        }
    }

    .filters {
        gap: 8px;

        :deep(.select) {
            min-width: 0;
            flex: 1 1 calc(50% - 4px);
        }
    }

    .series-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
}
</style>
