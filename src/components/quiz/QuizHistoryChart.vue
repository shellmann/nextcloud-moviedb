<template>
	<div class="quiz-history-chart">
		<p v-if="!bars.length" class="empty-message">
			{{ t('moviedb', 'No quiz played yet.') }}
		</p>
		<svg
			v-else
			class="quiz-history-svg"
			:class="{ 'is-mounted': isMounted }"
			:viewBox="`0 0 ${width} ${height}`"
			preserveAspectRatio="xMidYMid meet"
			role="img"
			:aria-label="ariaLabel">
			<defs>
				<linearGradient
					id="quiz-history-bar-gradient"
					x1="0"
					y1="0"
					x2="0"
					y2="1">
					<stop offset="0%" stop-color="var(--color-primary-element)" stop-opacity="0.75" />
					<stop offset="100%" stop-color="var(--color-primary-element)" />
				</linearGradient>
			</defs>
			<line
				v-if="best !== null"
				class="best-line"
				:x1="0"
				:x2="width"
				:y1="bestY"
				:y2="bestY" />
			<g v-for="(bar, i) in bars" :key="bar.id">
				<rect
					:x="bar.x"
					:y="isMounted ? bar.y : chartBottomY"
					:width="barWidth"
					:height="isMounted ? bar.barHeight : 0"
					rx="4"
					class="bar" />
				<text
					:x="bar.x + barWidth / 2"
					:y="bar.y - 6"
					text-anchor="middle"
					class="bar-count">
					{{ bar.percent }}
				</text>
				<text
					v-if="i % labelStep === 0"
					:x="bar.x + barWidth / 2"
					:y="height - 4"
					text-anchor="middle"
					class="bar-label">
					{{ bar.label }}
				</text>
			</g>
		</svg>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * QuizHistoryChart - score in percent per finished round (oldest left),
 * with a dashed line at the personal best. Inline SVG, no chart library.
 */
export default {
	name: 'QuizHistoryChart',

	props: {
		/** @type {Array<{id: number, playedAt: string, percent: number}>} Oldest first */
		rounds: { type: Array, required: true },
		/** Best percent, or null while nothing was played. */
		best: { type: Number, default: null },
	},

	data() {
		return {
			height: 220,
			barWidth: 24,
			gap: 10,
			chartBottom: 30,
			chartTop: 24,
			isMounted: false,
		}
	},

	computed: {
		width() {
			return Math.max(this.rounds.length * (this.barWidth + this.gap) + this.gap, 200)
		},

		plotHeight() {
			return this.height - this.chartBottom - this.chartTop
		},

		chartBottomY() {
			return this.chartTop + this.plotHeight
		},

		bestY() {
			return this.chartTop + this.plotHeight - (this.best / 100) * this.plotHeight
		},

		/** Show every n-th date label so 30 rounds stay readable. */
		labelStep() {
			return Math.max(1, Math.ceil(this.rounds.length / 10))
		},

		ariaLabel() {
			const scores = this.rounds.map((r) => `${r.percent}%`).join(', ')
			return t('moviedb', 'Score per quiz round in percent') + ': ' + scores
		},

		bars() {
			return this.rounds.map((round, index) => {
				const barHeight = (Math.max(0, Math.min(100, round.percent)) / 100) * this.plotHeight
				return {
					id: round.id,
					percent: round.percent,
					label: this.formatDate(round.playedAt),
					x: index * (this.barWidth + this.gap) + this.gap,
					y: this.chartTop + (this.plotHeight - barHeight),
					barHeight,
				}
			})
		},
	},

	mounted() {
		requestAnimationFrame(() => {
			this.isMounted = true
		})
	},

	methods: {
		formatDate(playedAt) {
			const date = new Date(String(playedAt).replace(' ', 'T'))
			if (Number.isNaN(date.getTime())) {
				return ''
			}
			return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
		},
	},
}
</script>

<style lang="scss" scoped>
.quiz-history-chart {
	width: 100%;
}

.quiz-history-svg {
	width: 100%;
	height: auto;
	max-height: 220px;
}

.bar {
	fill: url(#quiz-history-bar-gradient);
	transition: height 0.5s ease, y 0.5s ease;
}

.best-line {
	stroke: var(--color-success-text);
	stroke-width: 1.5;
	stroke-dasharray: 6 4;
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

.quiz-history-svg.is-mounted .bar-count {
	opacity: 1;
}

.empty-message {
	color: var(--color-text-lighter);
	text-align: center;
	padding: 20px 0;
}

@media (prefers-reduced-motion: reduce) {
	.bar,
	.bar-count {
		transition: none;
	}
}
</style>
