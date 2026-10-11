<template>
	<NcDialog
		:open="open"
		:name="t('moviedb', 'What should we watch tonight?')"
		size="large"
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
					@update:modelValue="load" />
				<NcCheckboxRadioSwitch v-model="highPriorityOnly" @update:modelValue="load">
					{{ t('moviedb', 'High priority only') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="picker-stage">
				<div class="picker-wheel-side">
					<div v-if="loading" class="picker-loading">
						<NcLoadingIcon :size="32" />
					</div>

					<template v-else-if="items.length">
						<WatchlistWheel
							ref="wheel"
							:items="items"
							@wheelClick="spin" />
						<p class="picker-hint">
							{{ t('moviedb', 'Click the wheel or press the button to spin.') }}
						</p>
					</template>

					<p v-else-if="searched" class="picker-empty">
						{{ t('moviedb', 'No title on your watchlist matches these filters.') }}
					</p>
				</div>
				<!-- Persistent live region so the outcome is announced to screen readers. -->
				<div class="picker-live" aria-live="polite" aria-atomic="true">
					<span v-if="spinning" class="picker-sr-only">{{ t('moviedb', 'Spinning the wheel') }}</span>
					<div v-else-if="item" :key="item.id" class="picker-result">
						<img
							v-if="item.posterPath"
							class="picker-poster"
							:src="getPosterUrl(item.posterPath)"
							alt="">
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
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('moviedb', 'Close') }}
			</NcButton>
			<NcButton v-if="item && canEdit" @click="markWatched">
				{{ t('moviedb', 'Mark as Watched') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="loading || spinning || !items.length"
				@click="spin">
				<template #icon>
					<FerrisWheel :size="20" />
				</template>
				{{ item ? t('moviedb', 'Spin again') : t('moviedb', 'Spin the wheel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import FerrisWheel from 'vue-material-design-icons/FerrisWheel.vue'
import WatchlistWheel from './WatchlistWheel.vue'
import { getPosterUrl } from '../composables/usePosterUrl.js'
import { getGenreOptions, getPriorityOptions, MEDIA_TYPE } from '../constants.js'
import { useWatchlistStore } from '../stores/watchlist.js'

const WHEEL_SEGMENTS = 8

export default {
	name: 'WatchlistPicker',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		FerrisWheel,
		NcLoadingIcon,
		NcSelect,
		WatchlistWheel,
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
			items: [],
			item: null,
			searched: false,
			loading: false,
			spinning: false,
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
				this.load()
			}
		},
	},

	created() {
		if (this.open) {
			this.load()
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
			this.load()
		},

		filters() {
			const filters = {}
			if (this.mediaType !== 'all') { filters.mediaType = this.mediaType }
			if (this.genre) { filters.genre = this.genre.id }
			if (this.highPriorityOnly) { filters.minPriority = 1 }
			return filters
		},

		/**
		 * Loads the titles that go on the wheel. Filters changed, so any earlier
		 * outcome no longer applies.
		 *
		 * @param {number|null} excludeId - Skip this title (the previous winner)
		 * @return {Promise<boolean>} false if the request failed
		 */
		async load(excludeId = null) {
			this.loading = true
			const filters = this.filters()
			if (excludeId) { filters.exclude = excludeId }
			const items = await this.watchlistStore.pickRandomSet(filters, WHEEL_SEGMENTS)
			this.loading = false
			if (items === false) {
				// The store already showed an error: keep what is on screen.
				return false
			}
			this.items = items
			this.item = null
			this.searched = true
			return true
		},

		/**
		 * Spin the wheel. After a first result the next spin brings a fresh
		 * selection of titles (never the previous winner), then lands on one of them.
		 */
		async spin() {
			if (this.loading || this.spinning || !this.items.length) { return }
			if (this.item) {
				const ok = await this.load(this.item.id)
				if (!ok || !this.items.length) { return }
				await this.$nextTick()
			}
			const index = Math.floor(Math.random() * this.items.length)
			this.spinning = true
			this.item = null
			await this.$refs.wheel?.spinTo(index)
			this.spinning = false
			this.item = this.items[index]
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
}

// Wheel and result side by side on wide dialogs, so both fit without scrolling.
.picker-stage {
	display: grid;
	grid-template-columns: minmax(0, 300px) minmax(0, 1fr);
	align-items: center;
	gap: 24px;
	min-height: 330px;

	@media (max-width: 700px) {
		grid-template-columns: minmax(0, 1fr);
	}
}

.picker-wheel-side {
	display: flex;
	flex-direction: column;
	gap: 8px;
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

.picker-sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
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

.picker-hint {
	margin: 0;
	text-align: center;
	color: var(--color-text-maxcontrast);
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
