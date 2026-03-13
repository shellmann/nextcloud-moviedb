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
			<div class="stat-card">
				<div class="stat-value">
					{{ stats.totalMovies }}
				</div>
				<div class="stat-label">
					{{ t('moviedb', 'Movies Watched') }}
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
			<div class="stat-card">
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
				<div v-if="recentMovies.length" class="movie-row">
					<MovieCard v-for="movie in recentMovies"
						:key="movie.id"
						:movie="movie"
						@click="goToMovie(movie.id)" />
				</div>
				<p v-else class="empty-message">
					{{ t('moviedb', 'No movies watched yet') }}
				</p>
			</div>

			<div class="section">
				<h3>{{ t('moviedb', 'Top Rated') }}</h3>
				<div v-if="topRatedMovies.length" class="movie-row">
					<MovieCard v-for="movie in topRatedMovies"
						:key="movie.id"
						:movie="movie"
						@click="goToMovie(movie.id)" />
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
import api from '../services/api.js'
import { useSettingsStore } from '../stores/settings.js'

export default {
	name: 'Dashboard',
	components: {
		NcNoteCard,
		MovieCard,
	},
	setup() {
		const settingsStore = useSettingsStore()
		return { settingsStore }
	},
	data() {
		return {
			stats: {
				totalMovies: 0,
				totalRuntimeHours: 0,
				averageRating: 0,
				watchlistCount: 0,
			},
			recentMovies: [],
			topRatedMovies: [],
			loading: true,
		}
	},
	computed: {
		hasApiKey() {
			return this.settingsStore.hasApiKey
		},
	},
	async created() {
		await this.loadDashboardData()
	},
	methods: {
		async loadDashboardData() {
			this.loading = true
			try {
				const [statsRes, recentRes, topRatedRes] = await Promise.all([
					api.getStats(),
					api.getRecentMovies(5),
					api.getTopRatedMovies(5),
				])
				this.stats = statsRes.data
				this.recentMovies = recentRes.data.movies
				this.topRatedMovies = topRatedRes.data.movies
			} catch (error) {
				console.error('Failed to load dashboard data:', error)
			} finally {
				this.loading = false
			}
		},
		goToMovie(id) {
			this.$router.push({ name: 'movie-detail', params: { id } })
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
