<template>
	<div class="movie-list">
		<div class="list-header">
			<h2>
				{{ t('moviedb', 'Movies') }}
				<span v-if="!loading" class="movie-count">({{ total }})</span>
			</h2>
			<div class="header-actions">
				<NcTextField v-model="searchQuery"
					:label="t('moviedb', 'Search')"
					:placeholder="t('moviedb', 'Search movies...')"
					@update:modelValue="debouncedSearch" />
				<NcButton v-if="activeCanEdit"
					:aria-label="t('moviedb', 'Add Movie')"
					:title="t('moviedb', 'Add Movie')"
					@click="$router.push({ name: 'add-movie' })">
					<template #icon>
						<Plus :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<div class="filters">
			<NcSelect v-model="selectedPlatform"
				:options="platformOptions"
				:placeholder="t('moviedb', 'All platforms')"
				:clearable="true"
				@update:modelValue="applyFilters" />
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

		<div v-else-if="movies.length" class="movie-grid">
			<MovieCard v-for="movie in movies"
				:key="movie.id"
				:movie="movie"
				@click="goToMovie(movie.id)" />
		</div>

		<NcEmptyContent v-else :name="emptyStateMessage">
			<template #icon>
				<Heart v-if="showFavoritesOnly" :size="64" />
				<Movie v-else :size="64" />
			</template>
			<template #description>
				<p v-if="showFavoritesOnly">
					{{ t('moviedb', 'Mark movies as favorite from the movie detail page.') }}
				</p>
			</template>
			<template #action>
				<NcButton v-if="!showFavoritesOnly && activeCanEdit" @click="$router.push({ name: 'add-movie' })">
					{{ t('moviedb', 'Add your first movie') }}
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
import Movie from 'vue-material-design-icons/Movie.vue'
import SortAscending from 'vue-material-design-icons/SortAscending.vue'
import SortDescending from 'vue-material-design-icons/SortDescending.vue'
import MovieCard from '../components/MovieCard.vue'
import { debounce } from '../utils/debounce.js'
import { useMoviesStore } from '../stores/movies.js'
import { usePlatformsStore } from '../stores/platforms.js'
import { useLibrariesStore } from '../stores/libraries.js'
import { GENRE_OPTIONS } from '../constants.js'

export default {
	name: 'MovieList',
	components: {
		NcTextField,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcEmptyContent,
		Plus,
		Heart,
		Movie,
		SortAscending,
		SortDescending,
		MovieCard,
	},
	setup() {
		const moviesStore = useMoviesStore()
		const platformsStore = usePlatformsStore()
		const librariesStore = useLibrariesStore()
		return { moviesStore, platformsStore, librariesStore }
	},
	data() {
		return {
			searchQuery: '',
			selectedPlatform: null,
			selectedGenre: null,
			sortBy: null,
			sortDirection: 'DESC',
			showFavoritesOnly: false,
		}
	},
	computed: {
		movies() {
			return this.moviesStore.movies
		},
		loading() {
			return this.moviesStore.loading
		},
		total() {
			return this.moviesStore.total
		},
		page() {
			return this.moviesStore.page
		},
		totalPages() {
			return this.moviesStore.totalPages
		},
		platforms() {
			return this.platformsStore.platforms
		},
		platformOptions() {
			return this.platforms.map(p => ({ id: p.id, label: p.name }))
		},
		genreOptions() {
			return GENRE_OPTIONS
		},
		sortOptions() {
			return [
				{ id: 'date_watched', label: t('moviedb', 'Date Watched') },
				{ id: 'title', label: t('moviedb', 'Title') },
				{ id: 'rating', label: t('moviedb', 'Rating') },
				{ id: 'release_year', label: t('moviedb', 'Release Year') },
			]
		},
		emptyStateMessage() {
			if (this.showFavoritesOnly) {
				return t('moviedb', 'No favorite movies yet')
			}
			return t('moviedb', 'No movies found')
		},
		activeCanEdit() {
			return this.librariesStore.activeCanEdit
		},
	},
	async created() {
		this.sortBy = this.sortOptions[0]
		this.debouncedSearch = debounce(this.applyFilters, 300)
		this.moviesStore.resetFilters()
		// Wait until libraries have loaded so the active library id is known;
		// otherwise this first fetch races App.vue's initial load and falls
		// back to the personal library instead of the active one.
		await this.librariesStore.whenReady()
		this.moviesStore.fetchAll()
	},
	methods: {
		applyFilters() {
			this.moviesStore.setFilters({
				search: this.searchQuery,
				platform: this.selectedPlatform?.id || null,
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
			this.moviesStore.setPage(page)
		},
		goToMovie(id) {
			this.$router.push({ name: 'movie-detail', params: { id } })
		},
	},
}
</script>

<style lang="scss" scoped>
.movie-list {
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

        .movie-count {
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

.movie-grid {
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

    .movie-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
}
</style>
