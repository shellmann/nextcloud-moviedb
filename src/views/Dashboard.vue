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
			<div
				class="stat-card clickable accent-primary"
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
			<div
				class="stat-card clickable accent-success"
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
			<div class="stat-card accent-warning">
				<div class="stat-value">
					{{ stats.totalEpisodesWatched }}
				</div>
				<div class="stat-label">
					{{ t('moviedb', 'Episodes Watched') }}
				</div>
			</div>
			<div class="stat-card accent-maxcontrast">
				<div class="stat-value">
					{{ stats.totalRuntimeHours }}h
				</div>
				<div class="stat-label">
					{{ t('moviedb', 'Total Runtime') }}
				</div>
			</div>
			<div class="stat-card accent-favorite">
				<div class="stat-value">
					{{ stats.averageRating || '-' }}
				</div>
				<div class="stat-label">
					{{ t('moviedb', 'Avg Rating') }}
				</div>
			</div>
			<div
				class="stat-card clickable accent-primary-light"
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
						<MovieCard
							v-if="item._type === 'movie'"
							:key="'movie-' + item.id"
							:movie="item"
							@click="goToMovie(item.id)" />
						<SeriesCard
							v-else
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
						<MovieCard
							v-if="item._type === 'movie'"
							:key="'movie-' + item.id"
							:movie="item"
							@click="goToMovie(item.id)" />
						<SeriesCard
							v-else
							:key="'series-' + item.id"
							:series="item"
							@click="goToSeries(item.id)" />
					</template>
				</div>
				<p v-else class="empty-message">
					{{ t('moviedb', 'Rate some movies to see them here') }}
				</p>
			</div>

			<div class="section">
				<div class="section-header">
					<h3>{{ t('moviedb', 'Watch Statistics') }}</h3>
					<NcSelect
						v-model="selectedChartMediaType"
						class="chart-media-filter"
						:options="chartMediaTypeOptions"
						:clearable="false"
						:aria-label="t('moviedb', 'Show')"
						@update:modelValue="onChartMediaTypeChange" />
				</div>
				<h4>{{ t('moviedb', 'Watches by Year') }}</h4>
				<YearChart :data="statsByYear" />
				<h4>{{ t('moviedb', 'Watches by Platform') }}</h4>
				<PlatformChart :data="statsByPlatform" />
			</div>
		</div>
	</div>
</template>

<script>
import { NcNoteCard, NcSelect } from '@nextcloud/vue'
import MovieCard from '../components/MovieCard.vue'
import PlatformChart from '../components/PlatformChart.vue'
import SeriesCard from '../components/SeriesCard.vue'
import YearChart from '../components/YearChart.vue'
import api from '../services/api.js'
import { useLibrariesStore } from '../stores/libraries.js'
import { useSettingsStore } from '../stores/settings.js'

export default {
	name: 'Dashboard',
	components: {
		NcNoteCard,
		NcSelect,
		MovieCard,
		SeriesCard,
		YearChart,
		PlatformChart,
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
			statsByYear: {},
			statsByPlatform: [],
			loading: true,

			selectedChartMediaType: null,
			chartMediaTypeOptions: [
				{ id: 'all', label: t('moviedb', 'All') },
				{ id: 'movie', label: t('moviedb', 'Movies') },
				{ id: 'series', label: t('moviedb', 'TV Shows') },
			],

			// Used by loadChartData()'s race guard — do not remove without
			// also removing the `seq`/`this.chartRequestSeq` check there.
			chartRequestSeq: 0,
		}
	},

	computed: {
		hasApiKey() {
			return this.settingsStore.hasApiKey
		},

		recentItems() {
			const movies = this.recentMovies.map((m) => ({ ...m, _type: 'movie' }))
			const series = this.recentSeries.map((s) => ({ ...s, _type: 'series' }))
			return [...movies, ...series]
				.sort((a, b) => (b.lastWatchedAt || '').localeCompare(a.lastWatchedAt || ''))
				.slice(0, 10)
		},

		topRatedItems() {
			const movies = this.topRatedMovies.map((m) => ({ ...m, _type: 'movie' }))
			const series = this.topRatedSeries.map((s) => ({ ...s, _type: 'series' }))
			return [...movies, ...series]
				.sort((a, b) => (b.lastRating ?? b.rating ?? 0) - (a.lastRating ?? a.rating ?? 0))
				.slice(0, 10)
		},
	},

	async created() {
		this.selectedChartMediaType = this.chartMediaTypeOptions[0]
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
				await this.loadChartData()
			} catch (error) {
				console.error('Failed to load dashboard data:', error)
			} finally {
				this.loading = false
			}
		},

		async loadChartData() {
			// Guard against out-of-order responses: if the user switches the
			// media-type filter again before this request resolves, a stale
			// response must not overwrite the data for the newer selection.
			const seq = ++this.chartRequestSeq
			try {
				const libraryId = this.librariesStore.activeLibraryId
				const lid = libraryId !== null ? libraryId : undefined
				const mediaType = this.selectedChartMediaType?.id !== 'all' ? this.selectedChartMediaType?.id : undefined
				const [byYearRes, byPlatformRes] = await Promise.all([
					api.getStatsByYear(lid, mediaType),
					api.getStatsByPlatform(lid, mediaType),
				])
				if (seq !== this.chartRequestSeq) { return }
				this.statsByYear = byYearRes.data.years || {}
				this.statsByPlatform = byPlatformRes.data.platforms || []
			} catch (error) {
				console.error('Failed to load chart data:', error)
			}
		},

		onChartMediaTypeChange() {
			this.loadChartData()
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
@use '../assets/design-tokens' as tokens;

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
    position: relative;
    background: var(--color-background-dark);
    border-radius: tokens.$radius-md;
    border-top: 3px solid var(--color-primary);
    box-shadow: tokens.$shadow-sm;
    padding: 20px;
    text-align: center;
    transition: transform tokens.$transition-base, box-shadow tokens.$transition-base;

    &.accent-success { border-top-color: var(--color-success); }
    &.accent-warning { border-top-color: var(--color-warning); }
    &.accent-maxcontrast { border-top-color: var(--color-text-maxcontrast); }
    &.accent-favorite { border-top-color: #e74c3c; }
    &.accent-primary-light { border-top-color: var(--color-primary-element-light-text); }

    &.clickable {
        cursor: pointer;

        &:hover,
        &:focus-visible {
            transform: translateY(-4px);
            box-shadow: tokens.$shadow-md;
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
        font-size: 19px;
        font-weight: 700;
        letter-spacing: -0.01em;
    }

    h4 {
        margin: 24px 0 12px;
        font-size: 15px;
        font-weight: 600;
        color: var(--color-text-lighter);

        &:first-of-type {
            margin-top: 0;
        }
    }
}

.section-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 8px 16px;

    h3 {
        margin: 0 0 16px;
    }
}

.chart-media-filter {
    width: 160px;
    max-width: 100%;
    margin-bottom: 16px;
}

.movie-row {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    padding-bottom: 8px;

    > * {
        flex: 0 0 140px;
        width: 140px;
    }
}

.empty-message {
    color: var(--color-text-lighter);
    font-style: italic;
}

@media (max-width: 600px) {
    .dashboard {
        padding: 12px;
    }

    .movie-row > * {
        flex-basis: 110px;
        width: 110px;
    }

    .section-header {
        h3 {
            margin: 0 0 8px;
        }
    }

    .chart-media-filter {
        width: 100%;
    }
}
</style>
