import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/moviedb/api')

export default {
	// Movies
	getMovies(params) {
		return axios.get(`${baseUrl}/movies`, { params })
	},
	getMovie(id, libraryId = undefined) {
		const params = libraryId !== undefined ? { libraryId } : {}
		return axios.get(`${baseUrl}/movies/${id}`, { params })
	},
	createMovie(data) {
		return axios.post(`${baseUrl}/movies`, data)
	},
	updateMovie(id, data) {
		return axios.put(`${baseUrl}/movies/${id}`, data)
	},
	deleteMovie(id, libraryId = undefined) {
		const data = libraryId !== undefined ? { libraryId } : {}
		return axios.delete(`${baseUrl}/movies/${id}`, { data })
	},

	// Watchlist
	getWatchlist(params) {
		return axios.get(`${baseUrl}/watchlist`, { params })
	},
	getWatchlistItem(id, libraryId = undefined) {
		const params = libraryId !== undefined ? { libraryId } : {}
		return axios.get(`${baseUrl}/watchlist/${id}`, { params })
	},
	addToWatchlist(data) {
		return axios.post(`${baseUrl}/watchlist`, data)
	},
	updateWatchlistItem(id, data) {
		return axios.put(`${baseUrl}/watchlist/${id}`, data)
	},
	removeFromWatchlist(id, libraryId = undefined) {
		const data = libraryId !== undefined ? { libraryId } : {}
		return axios.delete(`${baseUrl}/watchlist/${id}`, { data })
	},
	moveToWatched(id, watchData) {
		return axios.post(`${baseUrl}/watchlist/${id}/watched`, watchData)
	},

	// TMDB
	searchTmdb(query, year = null, page = 1, language = null, type = 'movie') {
		const params = { query, page, type }
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
	// TMDB — TV series
	searchTmdbSeries(query, year = null, page = 1, language = null) {
		const params = { query, page }
		if (year) {
			params.year = year
		}
		if (language) {
			params.language = language
		}
		return axios.get(`${baseUrl}/tmdb/series/search`, { params })
	},
	getTmdbSeriesDetails(tmdbId, language = null) {
		const params = {}
		if (language) {
			params.language = language
		}
		return axios.get(`${baseUrl}/tmdb/series/${tmdbId}`, { params })
	},
	getTmdbSeriesGenres(language = null) {
		const params = {}
		if (language) {
			params.language = language
		}
		return axios.get(`${baseUrl}/tmdb/series/genres`, { params })
	},
	getTmdbSeasonDetails(tmdbId, seasonNumber, language = null) {
		const params = {}
		if (language) {
			params.language = language
		}
		return axios.get(`${baseUrl}/tmdb/series/${tmdbId}/season/${seasonNumber}`, { params })
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

	// Watch history
	getWatches(movieId, libraryId = undefined) {
		const params = libraryId !== undefined ? { libraryId } : {}
		return axios.get(`${baseUrl}/movies/${movieId}/watches`, { params })
	},
	createWatch(movieId, data) {
		return axios.post(`${baseUrl}/movies/${movieId}/watches`, data)
	},
	updateWatch(movieId, watchId, data) {
		return axios.put(`${baseUrl}/movies/${movieId}/watches/${watchId}`, data)
	},
	deleteWatch(movieId, watchId, libraryId = undefined) {
		const data = libraryId !== undefined ? { libraryId } : {}
		return axios.delete(`${baseUrl}/movies/${movieId}/watches/${watchId}`, { data })
	},

	// Series
	getSeries(params) {
		return axios.get(`${baseUrl}/series`, { params })
	},
	getSeriesItem(id, libraryId = undefined) {
		const params = libraryId !== undefined ? { libraryId } : {}
		return axios.get(`${baseUrl}/series/${id}`, { params })
	},
	createSeries(data) {
		return axios.post(`${baseUrl}/series`, data)
	},
	updateSeries(id, data) {
		return axios.put(`${baseUrl}/series/${id}`, data)
	},
	deleteSeries(id, libraryId = undefined) {
		const data = libraryId !== undefined ? { libraryId } : {}
		return axios.delete(`${baseUrl}/series/${id}`, { data })
	},
	markSeriesWatched(id, watched = true, libraryId = undefined) {
		const body = { watched }
		if (libraryId !== undefined) body.libraryId = libraryId
		return axios.post(`${baseUrl}/series/${id}/watched`, body)
	},
	markEpisodeWatched(id, episodeId, watched = true, libraryId = undefined) {
		const body = { episodeId, watched }
		if (libraryId !== undefined) body.libraryId = libraryId
		return axios.post(`${baseUrl}/series/${id}/watched`, body)
	},
	markSeasonWatched(id, seasonNumber, watched = true, libraryId = undefined) {
		const body = { watched }
		if (libraryId !== undefined) body.libraryId = libraryId
		return axios.post(`${baseUrl}/series/${id}/seasons/${seasonNumber}/watched`, body)
	},

	// Statistics
	getStats(libraryId = undefined) {
		const params = libraryId !== undefined ? { libraryId } : {}
		return axios.get(`${baseUrl}/stats`, { params })
	},
	getStatsByYear(libraryId = undefined) {
		const params = libraryId !== undefined ? { libraryId } : {}
		return axios.get(`${baseUrl}/stats/years`, { params })
	},
	getStatsByPlatform(libraryId = undefined) {
		const params = libraryId !== undefined ? { libraryId } : {}
		return axios.get(`${baseUrl}/stats/platforms`, { params })
	},
	getStatsByGenre(libraryId = undefined) {
		const params = libraryId !== undefined ? { libraryId } : {}
		return axios.get(`${baseUrl}/stats/genres`, { params })
	},
	getRecentMovies(limit = 5, libraryId = undefined) {
		const params = { limit }
		if (libraryId !== undefined) params.libraryId = libraryId
		return axios.get(`${baseUrl}/stats/recent`, { params })
	},
	getTopRatedMovies(limit = 5, libraryId = undefined) {
		const params = { limit }
		if (libraryId !== undefined) params.libraryId = libraryId
		return axios.get(`${baseUrl}/stats/top-rated`, { params })
	},

	// Settings
	getSettings() {
		return axios.get(`${baseUrl}/settings`)
	},
	updateSettings(data) {
		return axios.put(`${baseUrl}/settings`, data)
	},

	// Libraries
	getLibraries() {
		return axios.get(`${baseUrl}/libraries`)
	},
	createLibrary(data) {
		return axios.post(`${baseUrl}/libraries`, data)
	},
	updateLibrary(id, data) {
		return axios.put(`${baseUrl}/libraries/${id}`, data)
	},
	deleteLibrary(id) {
		return axios.delete(`${baseUrl}/libraries/${id}`)
	},

	// Library members
	getLibraryMembers(id) {
		return axios.get(`${baseUrl}/libraries/${id}/members`)
	},
	addLibraryMember(id, data) {
		return axios.post(`${baseUrl}/libraries/${id}/members`, data)
	},
	removeLibraryMember(id, userId) {
		return axios.delete(`${baseUrl}/libraries/${id}/members/${userId}`)
	},
	leaveLibrary(id) {
		return axios.delete(`${baseUrl}/libraries/${id}/leave`)
	},

	// Sharee search
	searchSharees(query) {
		return axios.get(`${baseUrl}/libraries/sharees`, { params: { search: query } })
	},
}
