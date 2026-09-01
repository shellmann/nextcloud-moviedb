<template>
	<div class="dashboard">
		<div class="dashboard-header">
			<h2>{{ t('moviedb', 'Dashboard') }}</h2>
		</div>

		<div v-if="!hasApiKey" class="api-key-warning">
			<NcNoteCard type="warning">
				<p>
					<strong>{{ t('moviedb', 'TMDB API Key Required') }}</strong><br>
					{{ t('moviedb', 'To search for movies and fetch metadata, you need a free TMDB API key.') }}
					<router-link :to="{ name: 'settings' }">
						{{ t('moviedb', 'Settings') }}
					</router-link>.
				</p>
			</NcNoteCard>
		</div>

		<div class="stats-grid">
			<div class="stat-card clickable"
				role="link"
				tabindex="0"
				@click="$router.push({ name: 'movies' })"
				@keydown.enter="$router.push({ name: 'movies' })">
				<div class="stat-value">
					{{ stats.totalMovies }}
				</div>
				<div class="stat-label">
					{{ t('moviedb', 'Movies Watched') }}
				</div>
			</div>
			<div class="stat-card clickable"
				role="link"
				tabindex="0"
				@click="$router.push({ name: 'series' })"
				@keydown.enter="$router.push({ name: 'series' })">
				<div class="stat-value">
					{{ stats.totalSeries }}
				</div>
				<div class="stat-label">
					{{ t('moviedb', 'TV Shows') }}
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-value">
					{{ stats.totalEpisodesWatched }}
				</div>
				<div class="stat-label">
					{{ t('moviedb', 'Episodes Watched') }}
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-value">
					{{ stats.totalRuntimeHours }}h
				</div>
				<div class="stat-label">
					{{ t('moviedb', 'Total Runtime') }}
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-value">
					{{ stats.averageRating || '-' }}
				</div>
				<div class="stat-label">
					{{ t('moviedb', 'Avg Rating') }}
				</div>
			</div>
			<div class="stat-card clickable"
				role="link"
				tabindex="0"
				@click="$router.push({ name: 'watchlist' })"
				@keydown.enter="$router.push({ name: 'watchlist' })">
				<div class="stat-value">
					{{ stats.watchlistCount }}
				</div>
				<div class="stat-label">
					{{ t('moviedb', 'In Watchlist') }}
				</div>
			</div>
		</div>

		<div class="dashboard-sections">
			<div class="section">
				<h3>{{ t('moviedb', 'Recently Watched') }}</h3>
				<div v-if="recentItems.length" class="movie-row">
					<template v-for="item in recentItems">
						<MovieCard v-if="item._type === 'movie'"
							:key="'movie-' + item.id"
							:movie="item"
							@click="goToMovie(item.id)" />
						<SeriesCard v-else
							:key="'series-' + item.id"
							:series="item"
							@click="goToSeries(item.id)" />
					</template>
				</div>
				<p v-else class="empty-message">
					{{ t('moviedb', 'No movies watched yet') }}
				</p>
			</div>

			<div class="section">
				<h3>{{ t('moviedb', 'Top Rated') }}</h3>
				<div v-if="topRatedItems.length" class="movie-row">
					<template v-for="item in topRatedItems">
						<MovieCard v-if="item._type === 'movie'"
							:key="'movie-' + item.id"
							:movie="item"
							@click="goToMovie(item.id)" />
						<SeriesCard v-else
							:key="'series-' + item.id"
							:series="item"
							@click="goToSeries(item.id)" />
					</template>
				</div>
				<p v-else class="empty-message">
					{{ t('moviedb', 'Rate some movies to see them here') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'
import MovieCard from '../components/MovieCard.vue'
import SeriesCard from '../components/SeriesCard.vue'
import api from '../services/api.js'
import { useSettingsStore } from '../stores/settings.js'
import { useLibrariesStore } from '../stores/libraries.js'

export default {
	name: 'Dashboard',
	components: {
		NcNoteCard,
		MovieCard,
		SeriesCard,
	},
	setup() {
		const settingsStore = useSettingsStore()
		const librariesStore = useLibrariesStore()
		return { settingsStore, librariesStore }
	},
	data() {
		return {
			stats: {
				totalMovies: 0,
				totalSeries: 0,
				totalEpisodesWatched: 0,
				totalRuntimeHours: 0,
				averageRating: 0,
				watchlistCount: 0,
			},
			recentMovies: [],
			recentSeries: [],
			topRatedMovies: [],
			topRatedSeries: [],
			loading: true,
		}
	},
	computed: {
		hasApiKey() {
			return this.settingsStore.hasApiKey
		},
		recentItems() {
			const movies = this.recentMovies.map(m => ({ ...m, _type: 'movie' }))
			const series = this.recentSeries.map(s => ({ ...s, _type: 'series' }))
			return [...movies, ...series]
				.sort((a, b) => (b.lastWatchedAt || '').localeCompare(a.lastWatchedAt || ''))
				.slice(0, 10)
		},
		topRatedItems() {
			const movies = this.topRatedMovies.map(m => ({ ...m, _type: 'movie' }))
			const series = this.topRatedSeries.map(s => ({ ...s, _type: 'series' }))
			return [...movies, ...series]
				.sort((a, b) => (b.lastRating ?? b.rating ?? 0) - (a.lastRating ?? a.rating ?? 0))
				.slice(0, 10)
		},
	},
	async created() {
		// Wait for libraries so the active library id is known before fetching.
		await this.librariesStore.whenReady()
		await this.loadDashboardData()
	},
	methods: {
		async loadDashboardData() {
			this.loading = true
			try {
				const libraryId = this.librariesStore.activeLibraryId
				const lid = libraryId !== null ? libraryId : undefined
				const [statsRes, recentRes, topRatedRes] = await Promise.all([
					api.getStats(lid),
					api.getRecentMovies(5, lid),
					api.getTopRatedMovies(5, lid),
				])
				this.stats = statsRes.data
				this.recentMovies = recentRes.data.movies
				this.recentSeries = recentRes.data.series || []
				this.topRatedMovies = topRatedRes.data.movies
				this.topRatedSeries = topRatedRes.data.series || []
			} catch (error) {
				console.error('Failed to load dashboard data:', error)
			} finally {
				this.loading = false
			}
		},
		goToMovie(id) {
			this.$router.push({ name: 'movie-detail', params: { id } })
		},
		goToSeries(id) {
			this.$router.push({ name: 'series-detail', params: { id } })
		},
	},
}
</script>

<style lang="scss" scoped>
.dashboard {
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
}

.dashboard-header {
    margin-bottom: 20px;

    h2 {
        margin: 0;
        font-size: 24px;
    }
}

.api-key-warning {
    margin-bottom: 20px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.stat-card {
    background: var(--color-background-dark);
    border-radius: 8px;
    padding: 20px;
    text-align: center;

    &.clickable {
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;

        &:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        &:focus-visible {
            outline: 2px solid var(--color-primary);
            outline-offset: 2px;
        }
    }

    .stat-value {
        font-size: 32px;
        font-weight: bold;
        color: var(--color-primary);
    }

    .stat-label {
        font-size: 14px;
        color: var(--color-text-lighter);
        margin-top: 4px;
    }
}

.dashboard-sections {
    display: flex;
    flex-direction: column;
    gap: 32px;
}

.section {
    h3 {
        margin: 0 0 16px;
        font-size: 18px;
    }
}

.movie-row {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    padding-bottom: 8px;
}

.empty-message {
    color: var(--color-text-lighter);
    font-style: italic;
}
</style>
