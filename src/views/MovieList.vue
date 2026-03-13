<template>
	<div class="movie-list">
		<div class="list-header">
			<h2>{{ t('moviedb', 'My Movies') }}</h2>
			<div class="header-actions">
				<NcTextField v-model="searchQuery"
					:label="t('moviedb', 'Search')"
					:placeholder="t('moviedb', 'Search movies...')"
					@update:modelValue="debouncedSearch" />
				<NcButton @click="$router.push({ name: 'add-movie' })">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('moviedb', 'Add Movie') }}
				</NcButton>
			</div>
		</div>

		<div class="filters">
			<NcSelect v-model="selectedPlatform"
				:options="platformOptions"
				:placeholder="t('moviedb', 'All platforms')"
				:clearable="true"
				@update:modelValue="applyFilters" />
			<NcSelect v-model="sortBy"
				:options="sortOptions"
				:placeholder="t('moviedb', 'Sort by')"
				@update:modelValue="applyFilters" />
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

		<NcEmptyContent v-else :name="t('moviedb', 'No movies found')">
			<template #icon>
				<Movie :size="64" />
			</template>
			<template #action>
				<NcButton @click="$router.push({ name: 'add-movie' })">
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
import Movie from 'vue-material-design-icons/Movie.vue'
import MovieCard from '../components/MovieCard.vue'
import { debounce } from '../utils/debounce.js'
import { useMoviesStore } from '../stores/movies.js'
import { usePlatformsStore } from '../stores/platforms.js'

export default {
	name: 'MovieList',
	components: {
		NcTextField,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcEmptyContent,
		Plus,
		Movie,
		MovieCard,
	},
	setup() {
		const moviesStore = useMoviesStore()
		const platformsStore = usePlatformsStore()
		return { moviesStore, platformsStore }
	},
	data() {
		return {
			searchQuery: '',
			selectedPlatform: null,
			sortBy: null,
		}
	},
	computed: {
		movies() {
			return this.moviesStore.movies
		},
		loading() {
			return this.moviesStore.loading
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
		sortOptions() {
			return [
				{ id: 'date_watched', label: t('moviedb', 'Date Watched') },
				{ id: 'title', label: t('moviedb', 'Title') },
				{ id: 'rating', label: t('moviedb', 'Rating') },
				{ id: 'release_year', label: t('moviedb', 'Release Year') },
			]
		},
	},
	created() {
		this.sortBy = this.sortOptions[0]
		this.debouncedSearch = debounce(this.applyFilters, 300)
		this.moviesStore.fetchAll()
	},
	methods: {
		applyFilters() {
			this.moviesStore.setFilters({
				search: this.searchQuery,
				platform: this.selectedPlatform?.id || null,
				sort: this.sortBy?.id || 'date_watched',
			})
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
</style>
