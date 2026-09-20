<template>
	<div class="year-chart">
		<p v-if="!bars.length" class="empty-message">
			{{ t('moviedb', 'No watch history yet.') }}
		</p>
		<svg
			v-else
			class="year-chart-svg"
			:class="{ 'is-mounted': isMounted }"
			:viewBox="`0 0 ${width} ${height}`"
			preserveAspectRatio="xMidYMid meet"
			role="img"
			:aria-label="t('moviedb', 'Watches by Year')">
			<defs>
				<linearGradient
					id="year-chart-bar-gradient"
					x1="0"
					y1="0"
					x2="0"
					y2="1">
					<stop offset="0%" stop-color="var(--color-primary-element)" stop-opacity="0.75" />
					<stop offset="100%" stop-color="var(--color-primary-element)" />
				</linearGradient>
			</defs>
			<g v-for="bar in bars" :key="bar.year">
				<rect
					:x="bar.x"
					:y="isMounted ? bar.y : chartTop + (bar.y - chartTop) + bar.barHeight"
					:width="barWidth"
					:height="isMounted ? bar.barHeight : 0"
					rx="4"
					class="bar" />
				<text
					:x="bar.x + barWidth / 2"
					:y="bar.y - 6"
					text-anchor="middle"
					class="bar-count">
					{{ bar.count }}
				</text>
				<text
					:x="bar.x + barWidth / 2"
					:y="height - 4"
					text-anchor="middle"
					class="bar-label">
					{{ bar.year }}
				</text>
			</g>
		</svg>
	</div>
</template>

<script>
/**
 * YearChart component - Renders watch counts per year as inline SVG bars.
 */
export default {
	name: 'YearChart',

	props: {
		/**
		 * Watch counts keyed by year, e.g. { "2024": 12, "2023": 8 }
		 *
		 * @type {Object<string, number>}
		 */
		data: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			height: 220,
			barWidth: 32,
			gap: 16,
			chartBottom: 30,
			chartTop: 24,
			isMounted: false,
		}
	},

	computed: {
		width() {
			const count = Object.keys(this.data).length
			return Math.max(count * (this.barWidth + this.gap) + this.gap, 200)
		},

		bars() {
			const years = Object.keys(this.data).sort((a, b) => Number(a) - Number(b))
			if (!years.length) {
				return []
			}
			const maxCount = Math.max(...years.map((y) => this.data[y]))
			const plotHeight = this.height - this.chartBottom - this.chartTop
			return years.map((year, index) => {
				const count = this.data[year]
				const barHeight = maxCount > 0 ? (count / maxCount) * plotHeight : 0
				return {
					year,
					count,
					x: index * (this.barWidth + this.gap) + this.gap,
					y: this.chartTop + (plotHeight - barHeight),
					barHeight,
				}
			})
		},
	},

	mounted() {
		// Grow the bars in from zero height on first render (a plain CSS
		// transition on the SVG rect's height/y, no animation library).
		requestAnimationFrame(() => {
			this.isMounted = true
		})
	},
}
</script>

<style lang="scss" scoped>
.year-chart {
    width: 100%;
}

.year-chart-svg {
    width: 100%;
    height: auto;
    max-height: 220px;
}

.bar {
    fill: url(#year-chart-bar-gradient);
    transition: height 0.5s ease, y 0.5s ease, opacity 0.15s ease;

    &:hover {
        opacity: 0.8;
    }
}

.bar-count,
.bar-label {
    font-size: 11px;
    fill: var(--color-text-lighter);
}

.bar-count {
    font-weight: 600;
    opacity: 0;
    transition: opacity 0.3s ease 0.4s;
}

.year-chart-svg.is-mounted .bar-count {
    opacity: 1;
}

.empty-message {
    color: var(--color-text-lighter);
    text-align: center;
    padding: 20px 0;
}
</style>
