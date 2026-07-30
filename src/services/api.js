import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/moviedb/api')

export default {
	// Movies
	getMovies(params) {
		return axios.get(`${baseUrl}/movies`, { params })
	},
	getMovie(id) {
		return axios.get(`${baseUrl}/movies/${id}`)
	},
	createMovie(data) {
		return axios.post(`${baseUrl}/movies`, data)
	},
	updateMovie(id, data) {
		return axios.put(`${baseUrl}/movies/${id}`, data)
	},
	deleteMovie(id) {
		return axios.delete(`${baseUrl}/movies/${id}`)
	},

	// Watchlist
	getWatchlist(params) {
		return axios.get(`${baseUrl}/watchlist`, { params })
	},
	getWatchlistItem(id) {
		return axios.get(`${baseUrl}/watchlist/${id}`)
	},
	addToWatchlist(data) {
		return axios.post(`${baseUrl}/watchlist`, data)
	},
	updateWatchlistItem(id, data) {
		return axios.put(`${baseUrl}/watchlist/${id}`, data)
	},
	removeFromWatchlist(id) {
		return axios.delete(`${baseUrl}/watchlist/${id}`)
	},
	moveToWatched(id, watchData) {
		return axios.post(`${baseUrl}/watchlist/${id}/watched`, watchData)
	},

	// TMDB
	searchTmdb(query, year = null, page = 1, language = null) {
		const params = { query, page }
		if (year) {
			params.year = year
		}
		if (language) {
			params.language = language
		}
		return axios.get(`${baseUrl}/tmdb/search`, { params })
	},
	getTmdbMovieDetails(tmdbId, language = null) {
		const params = {}
		if (language) {
			params.language = language
		}
		return axios.get(`${baseUrl}/tmdb/movie/${tmdbId}`, { params })
	},
	getTmdbGenres(language = null) {
		const params = {}
		if (language) {
			params.language = language
		}
		return axios.get(`${baseUrl}/tmdb/genres`, { params })
	},
	checkTmdbApiKey() {
		return axios.get(`${baseUrl}/tmdb/check`)
	},

	// Platforms
	getPlatforms() {
		return axios.get(`${baseUrl}/platforms`)
	},
	createPlatform(data) {
		return axios.post(`${baseUrl}/platforms`, data)
	},
	updatePlatform(id, data) {
		return axios.put(`${baseUrl}/platforms/${id}`, data)
	},
	deletePlatform(id) {
		return axios.delete(`${baseUrl}/platforms/${id}`)
	},

	// Statistics
	getStats() {
		return axios.get(`${baseUrl}/stats`)
	},
	getStatsByYear() {
		return axios.get(`${baseUrl}/stats/years`)
	},
	getStatsByPlatform() {
		return axios.get(`${baseUrl}/stats/platforms`)
	},
	getStatsByGenre() {
		return axios.get(`${baseUrl}/stats/genres`)
	},
	getRecentMovies(limit = 5) {
		return axios.get(`${baseUrl}/stats/recent`, { params: { limit } })
	},
	getTopRatedMovies(limit = 5) {
		return axios.get(`${baseUrl}/stats/top-rated`, { params: { limit } })
	},

	// Settings
	getSettings() {
		return axios.get(`${baseUrl}/settings`)
	},
	updateSettings(data) {
		return axios.put(`${baseUrl}/settings`, data)
	},
}
