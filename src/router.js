import { createRouter, createWebHashHistory } from 'vue-router'
import Dashboard from './views/Dashboard.vue'
import MovieList from './views/MovieList.vue'
import AddMovie from './views/AddMovie.vue'
import MovieDetail from './views/MovieDetail.vue'
import EditMovie from './views/EditMovie.vue'
import SeriesList from './views/SeriesList.vue'
import AddSeries from './views/AddSeries.vue'
import SeriesDetail from './views/SeriesDetail.vue'
import EditSeries from './views/EditSeries.vue'
import Watchlist from './views/Watchlist.vue'
import AddToWatchlist from './views/AddToWatchlist.vue'
import Settings from './views/Settings.vue'

const routes = [
	{
		path: '/',
		name: 'dashboard',
		component: Dashboard,
	},
	{
		path: '/movies',
		name: 'movies',
		component: MovieList,
	},
	{
		path: '/movies/add',
		name: 'add-movie',
		component: AddMovie,
	},
	{
		path: '/movies/:id',
		name: 'movie-detail',
		component: MovieDetail,
		props: true,
	},
	{
		path: '/movies/:id/edit',
		name: 'edit-movie',
		component: EditMovie,
		props: true,
	},
	{
		path: '/tv',
		name: 'series',
		component: SeriesList,
	},
	{
		path: '/tv/add',
		name: 'add-series',
		component: AddSeries,
	},
	{
		path: '/tv/:id',
		name: 'series-detail',
		component: SeriesDetail,
		props: true,
	},
	{
		path: '/tv/:id/edit',
		name: 'edit-series',
		component: EditSeries,
		props: true,
	},
	{
		path: '/watchlist',
		name: 'watchlist',
		component: Watchlist,
	},
	{
		path: '/watchlist/add',
		name: 'add-to-watchlist',
		component: AddToWatchlist,
	},
	{
		path: '/settings',
		name: 'settings',
		component: Settings,
	},
]

const router = createRouter({
	history: createWebHashHistory('/apps/moviedb/'),
	routes,
})

export default router
