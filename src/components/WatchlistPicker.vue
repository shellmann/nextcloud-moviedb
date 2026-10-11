<template>
	<NcDialog
		:open="open"
		:name="t('moviedb', 'What should we watch tonight?')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="watchlist-picker">
			<div class="picker-filters">
				<div class="picker-type" role="radiogroup" :aria-label="t('moviedb', 'Filter by type')">
					<NcCheckboxRadioSwitch
						v-for="option in typeOptions"
						:key="option.id"
						:modelValue="mediaType"
						:value="option.id"
						name="picker-media-type"
						type="radio"
						buttonVariant
						buttonVariantGrouped="horizontal"
						@update:modelValue="onTypeChange">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcSelect
					v-model="genre"
					class="picker-genre"
					:options="genreOptions"
					:placeholder="t('moviedb', 'Genre')"
					:aria-label="t('moviedb', 'Genre')"
					@update:modelValue="pick(false)" />
				<NcCheckboxRadioSwitch v-model="highPriorityOnly" @update:modelValue="pick(false)">
					{{ t('moviedb', 'High priority only') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div v-if="loading && !item" class="picker-loading">
				<NcLoadingIcon :size="32" />
			</div>

			<div v-else-if="item" :key="item.id" class="picker-result">
				<img
					v-if="item.posterPath"
					class="picker-poster"
					:src="getPosterUrl(item.posterPath)"
					:alt="item.title">
				<div v-else class="picker-poster picker-no-poster">
					{{ t('moviedb', 'No poster') }}
				</div>
				<div class="picker-info">
					<h3>{{ item.title }}</h3>
					<p v-if="year" class="picker-year">
						{{ year }}
					</p>
					<div class="picker-tags">
						<span v-for="genreName in genreNames" :key="genreName" class="picker-tag">{{ genreName }}</span>
						<span v-if="item.priority > 0" class="picker-tag picker-priority">{{ priorityLabel }}</span>
					</div>
					<p v-if="item.overview" class="picker-overview">
						{{ item.overview }}
					</p>
				</div>
			</div>

			<p v-else-if="searched" class="picker-empty">
				{{ t('moviedb', 'No title on your watchlist matches these filters.') }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('moviedb', 'Close') }}
			</NcButton>
			<NcButton v-if="item && canEdit" @click="markWatched">
				{{ t('moviedb', 'Mark as Watched') }}
			</NcButton>
			<NcButton variant="primary" :disabled="loading" @click="pick(true)">
				{{ t('moviedb', 'Spin again') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { getGenreOptions, getPriorityOptions, MEDIA_TYPE } from '../constants.js'
import { useWatchlistStore } from '../stores/watchlist.js'

export default {
	name: 'WatchlistPicker',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
	},

	props: {
		open: { type: Boolean, default: false },
		canEdit: { type: Boolean, default: false },
	},

	emits: ['update:open', 'markWatched'],

	setup() {
		const watchlistStore = useWatchlistStore()
		return { watchlistStore }
	},

	data() {
		return {
			mediaType: 'all',
			genre: null,
			highPriorityOnly: false,
			item: null,
			searched: false,
			loading: false,
			typeOptions: [
				{ id: 'all', label: t('moviedb', 'All') },
				{ id: MEDIA_TYPE.MOVIE, label: t('moviedb', 'Movies') },
				{ id: MEDIA_TYPE.SERIES, label: t('moviedb', 'TV Shows') },
			],
		}
	},

	computed: {
		genreOptions() {
			if (this.mediaType !== 'all') {
				return getGenreOptions(this.mediaType)
			}
			// Movie and TV genre lists overlap; show each id once.
			const byId = new Map()
			for (const g of [...getGenreOptions(MEDIA_TYPE.MOVIE), ...getGenreOptions(MEDIA_TYPE.SERIES)]) {
				byId.set(g.id, g)
			}
			return [...byId.values()].sort((a, b) => a.label.localeCompare(b.label))
		},

		year() {
			return this.item?.releaseDate ? String(this.item.releaseDate).slice(0, 4) : ''
		},

		genreNames() {
			const raw = this.item?.genreIds
			if (!raw) { return [] }
			const ids = Array.isArray(raw) ? raw : JSON.parse(raw || '[]')
			const options = getGenreOptions(this.item.mediaType === MEDIA_TYPE.SERIES ? MEDIA_TYPE.SERIES : MEDIA_TYPE.MOVIE)
			return ids.map((id) => options.find((g) => g.id === id)?.label).filter(Boolean).slice(0, 3)
		},

		priorityLabel() {
			return getPriorityOptions().find((p) => p.id === this.item?.priority)?.label ?? ''
		},
	},

	watch: {
		open(isOpen) {
			if (isOpen) {
				this.item = null
				this.searched = false
				this.pick(false)
			}
		},
	},

	created() {
		if (this.open) {
			this.pick(false)
		}
	},

	methods: {
		getPosterUrl,

		onTypeChange(type) {
			this.mediaType = type
			// A genre of the other media type may not exist in the new list.
			if (this.genre && !this.genreOptions.some((g) => g.id === this.genre.id)) {
				this.genre = null
			}
			this.pick(false)
		},

		/**
		 * @param {boolean} excludeCurrent - skip the shown item ("spin again")
		 */
		async pick(excludeCurrent) {
			this.loading = true
			const filters = {}
			if (this.mediaType !== 'all') { filters.mediaType = this.mediaType }
			if (this.genre) { filters.genre = this.genre.id }
			if (this.highPriorityOnly) { filters.minPriority = 1 }
			if (excludeCurrent && this.item) { filters.exclude = this.item.id }

			const result = await this.watchlistStore.pickRandom(filters)
			this.loading = false
			if (result === false) {
				// Request failed (the store already showed an error): keep what is shown.
				return
			}
			this.item = result
			this.searched = true
		},

		markWatched() {
			this.$emit('markWatched', this.item)
			this.$emit('update:open', false)
		},
	},
}
</script>

<style lang="scss" scoped>
.watchlist-picker {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-height: 260px;
}

.picker-filters {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
}

.picker-type {
	display: flex;
}

.picker-genre {
	min-width: 180px;
}

.picker-loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.picker-result {
	display: flex;
	gap: 16px;
	animation: picker-pop 0.25s ease-out;

	@media (max-width: 600px) {
		flex-direction: column;
		align-items: center;
	}
}

.picker-poster {
	flex-shrink: 0;
	width: 140px;
	border-radius: 8px;
}

.picker-no-poster {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 210px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.picker-info {
	h3 {
		margin: 0;
	}
}

.picker-year {
	margin: 4px 0 8px;
	color: var(--color-text-maxcontrast);
}

.picker-tags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 8px;
}

.picker-tag {
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-background-dark);
	font-size: 12px;
}

.picker-priority {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.picker-overview {
	display: -webkit-box;
	-webkit-line-clamp: 3;
	line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
	margin: 0;
}

.picker-empty {
	padding: 32px 0;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

@keyframes picker-pop {
	from {
		opacity: 0;
		transform: scale(0.96);
	}
	to {
		opacity: 1;
		transform: scale(1);
	}
}

@media (prefers-reduced-motion: reduce) {
	.picker-result {
		animation: none;
	}
}
</style>
