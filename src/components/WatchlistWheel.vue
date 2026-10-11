<template>
	<div class="wheel" @click="$emit('wheelClick')">
		<!-- Purely visual: the result is announced through the dialog's live region. -->
		<svg
			class="wheel-svg"
			viewBox="0 0 300 300"
			aria-hidden="true"
			focusable="false">
			<g
				class="wheel-disc"
				:style="discStyle"
				@transitionend.self="onTransitionEnd">
				<template v-if="segments.length === 1">
					<circle
						cx="150"
						cy="150"
						r="140"
						:fill="segments[0].color" />
				</template>
				<path
					v-for="segment in segments.length > 1 ? segments : []"
					:key="segment.id"
					:d="segment.path"
					:fill="segment.color"
					class="wheel-segment" />
				<g
					v-for="segment in segments"
					:key="'label-' + segment.id"
					:transform="`rotate(${segment.centre - 90} 150 150)`">
					<text
						x="266"
						y="150"
						text-anchor="end"
						dominant-baseline="central"
						class="wheel-label">{{ segment.label }}</text>
				</g>
			</g>
			<circle
				cx="150"
				cy="150"
				r="22"
				class="wheel-hub" />
			<!-- pointer -->
			<polygon points="134,-6 166,-6 150,26" class="wheel-pointer" />
		</svg>
	</div>
</template>

<script>
const SPIN_MS = 4200
const EXTRA_TURNS = 5
const MAX_LABEL = 13

/**
 * A prize wheel. Purely presentational: the parent says which segment must land
 * under the pointer (at the top) through spinTo(), and waits for the promise.
 * Without animation (reduced motion) it jumps straight there.
 */
export default {
	name: 'WatchlistWheel',

	props: {
		/** @type {Array<{id: number, title: string}>} */
		items: { type: Array, default: () => [] },
	},

	emits: ['wheelClick'],

	data() {
		return { rotation: 0, animating: false, pendingResolve: null, timer: null }
	},

	computed: {
		step() {
			return 360 / Math.max(1, this.items.length)
		},

		segments() {
			const n = this.items.length
			return this.items.map((item, i) => {
				const start = i * this.step
				const end = (i + 1) * this.step
				return {
					id: item.id,
					centre: (start + end) / 2,
					color: `hsl(${Math.round((i * 360) / Math.max(n, 6) + 205) % 360} 55% 36%)`,
					label: item.title.length > MAX_LABEL ? item.title.slice(0, MAX_LABEL - 1) + '…' : item.title,
					path: this.slice(start, end),
				}
			})
		},

		discStyle() {
			return {
				transform: `rotate(${this.rotation}deg)`,
				transformOrigin: '150px 150px',
				transition: this.animating ? `transform ${SPIN_MS}ms cubic-bezier(0.12, 0.6, 0.1, 1)` : 'none',
			}
		},
	},

	watch: {
		// A new set of segments starts from a clean wheel.
		items() {
			this.cancel()
			this.animating = false
			this.rotation = 0
		},
	},

	beforeUnmount() {
		this.cancel()
	},

	methods: {
		/**
		 * SVG path of one slice; angles are clockwise from the top.
		 *
		 * @param startDeg
		 * @param endDeg
		 */
		slice(startDeg, endDeg) {
			const point = (deg) => {
				const rad = (deg - 90) * Math.PI / 180
				return [150 + 140 * Math.cos(rad), 150 + 140 * Math.sin(rad)]
			}
			const [x1, y1] = point(startDeg)
			const [x2, y2] = point(endDeg)
			const large = endDeg - startDeg > 180 ? 1 : 0
			return `M150 150 L${x1.toFixed(2)} ${y1.toFixed(2)} A140 140 0 ${large} 1 ${x2.toFixed(2)} ${y2.toFixed(2)} Z`
		},

		cancel() {
			clearTimeout(this.timer)
			if (this.pendingResolve) {
				this.pendingResolve()
				this.pendingResolve = null
			}
		},

		/**
		 * Spin so that segment `index` ends up under the pointer.
		 *
		 * @param {number} index - Segment that must win
		 * @return {Promise<void>} resolves when the wheel has stopped
		 */
		async spinTo(index) {
			this.cancel()
			const animate = window.matchMedia?.('(prefers-reduced-motion: no-preference)').matches
			// land somewhere inside the segment, not always on its centre line
			const jitter = (Math.random() - 0.5) * this.step * 0.7
			const target = (360 - (index + 0.5) * this.step + jitter + 360) % 360
			const base = Math.ceil(this.rotation / 360) * 360
			const next = base + EXTRA_TURNS * 360 + target

			if (!animate) {
				this.animating = false
				this.rotation = next
				return
			}

			// Commit the starting position (transition off) to the screen first. A
			// new set of titles resets the wheel to 0; without this the reset and the
			// new target land in the same frame, the browser never sees the start and
			// the second spin shows no animation.
			this.animating = false
			await this.$nextTick()
			void this.$el.getBoundingClientRect()

			await new Promise((resolve) => {
				this.pendingResolve = resolve
				this.animating = true
				this.rotation = next
				// transitionend can be missed (hidden tab, interrupted): don't hang.
				this.timer = setTimeout(this.finish, SPIN_MS + 300)
			})
		},

		onTransitionEnd() {
			this.finish()
		},

		finish() {
			clearTimeout(this.timer)
			if (this.pendingResolve) {
				const resolve = this.pendingResolve
				this.pendingResolve = null
				resolve()
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.wheel {
	display: flex;
	justify-content: center;
	cursor: pointer;
}

.wheel-svg {
	width: 100%;
	max-width: 300px;
	height: auto;
	overflow: visible;
	filter: drop-shadow(0 4px 8px var(--color-box-shadow));
}

.wheel-segment {
	stroke: var(--color-main-background);
	stroke-width: 2;
}

.wheel-label {
	font-size: 12.5px;
	font-weight: 600;
	fill: #fff;
}

.wheel-hub {
	fill: var(--color-main-background);
	stroke: var(--color-border-maxcontrast);
	stroke-width: 3;
}

.wheel-pointer {
	fill: var(--color-main-text);
	stroke: var(--color-main-background);
	stroke-width: 2;
}
</style>
