<template>
	<div class="edit-movie">
		<div class="page-header">
			<h2>{{ t('moviedb', 'Edit Movie') }}</h2>
		</div>

		<div v-if="loading" class="loading">
			<NcLoadingIcon :size="44" />
		</div>

		<div v-else-if="movie" class="movie-form-section">
			<MovieForm :movie="formData"
				:platforms="platforms"
				:saving="saving"
				edit-mode
				@submit="saveMovie"
				@cancel="$router.back()" />
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import MovieForm from '../components/MovieForm.vue'
import { useMoviesStore } from '../stores/movies.js'
import { usePlatformsStore } from '../stores/platforms.js'

export default {
	name: 'EditMovie',
	components: {
		NcLoadingIcon,
		MovieForm,
	},
	props: {
		id: {
			type: [String, Number],
			required: true,
		},
	},
	setup() {
		const moviesStore = useMoviesStore()
		const platformsStore = usePlatformsStore()
		return { moviesStore, platformsStore }
	},
	data() {
		return {
			formData: null,
			saving: false,
		}
	},
	computed: {
		movie() {
			return this.moviesStore.currentMovie
		},
		loading() {
			return this.moviesStore.loading
		},
		platforms() {
			return this.platformsStore.platforms
		},
	},
	watch: {
		movie: {
			immediate: true,
			handler(movie) {
				if (movie) {
					this.formData = { ...movie }
				}
			},
		},
	},
	created() {
		this.loadMovie()
	},
	methods: {
		async loadMovie() {
			await this.moviesStore.fetchOne(this.id)
		},
		async saveMovie(movieData) {
			this.saving = true
			try {
				await this.moviesStore.update(this.id, movieData)
				showSuccess(t('moviedb', 'Movie updated successfully.'))
				this.$router.push({ name: 'movie-detail', params: { id: this.id } })
			} catch (error) {
				showError(t('moviedb', 'Failed to update movie. Please try again.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.edit-movie {
    padding: 20px;
    max-width: 900px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 20px;

    h2 {
        margin: 0;
        font-size: 24px;
    }
}

.loading {
    display: flex;
    justify-content: center;
    padding: 40px;
}

.movie-form-section {
    background: var(--color-background-dark);
    border-radius: 8px;
    padding: 20px;
}
</style>
