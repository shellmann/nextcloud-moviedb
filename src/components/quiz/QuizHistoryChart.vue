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
			preserveAspectRatio="xMinYMid meet"
			:style="{ maxWidth: width * 1.6 + 'px' }"
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
					class="bar">
					<title>{{ bar.title }}</title>
				</rect>
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
					:y="height - 6"
					text-anchor="middle"
					class="bar-label">
					{{ firstNumber + i }}
				</text>
			</g>
		</svg>
		<!-- First and last round only: dates under every bar never fit, the tooltip has the exact time. -->
		<p v-if="dateRange" class="date-range">
			{{ dateRange }}
		</p>
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
		/** Number of the first shown round (history is cut to the latest rounds). */
		firstNumber: { type: Number, default: 1 },
	},

	data() {
		return {
			height: 193,
			barWidth: 24,
			gap: 10,
			chartBottom: 18,
			chartTop: 24,
			isMounted: false,
		}
	},

	computed: {
		width() {
			return Math.max(this.rounds.length * (this.barWidth + this.gap) + this.gap, 280)
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
			const scores = this.rounds.map((r, i) => `${this.firstNumber + i}: ${r.percent}%`).join(', ')
			return t('moviedb', 'Score per quiz round in percent') + ': ' + scores
		},

		/**
		 * "12 Oct – 24 Oct" for the first and last shown round, or one date when all
		 * rounds fall on the same local day.
		 */
		dateRange() {
			if (!this.bars.length) { return '' }
			const first = this.bars[0]
			const last = this.bars[this.bars.length - 1]
			if (!first.label || !last.label) { return '' }
			return first.day === last.day ? first.label : `${first.label} – ${last.label}`
		},

		bars() {
			return this.rounds.map((round, index) => {
				const day = this.localDay(round.playedAt)
				const barHeight = (Math.max(0, Math.min(100, round.percent)) / 100) * this.plotHeight
				return {
					id: round.id,
					percent: round.percent,
					label: this.formatDate(round.playedAt),
					day,
					title: t('moviedb', 'Round {n}, {date}: {percent}%', { n: this.firstNumber + index, date: this.formatDateTime(round.playedAt), percent: round.percent }),
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
		/**
		 * The server stores timestamps in UTC without a zone marker ("2026-10-11 12:00:00").
		 * Parse them as UTC so they display in the viewer's own time zone.
		 *
		 * @param {string} playedAt - Server timestamp
		 * @return {Date}
		 */
		parseUtc(playedAt) {
			return new Date(String(playedAt).replace(' ', 'T') + 'Z')
		},

		/**
		 * Calendar day (YYYY-MM-DD) in the viewer's time zone, to group rounds by local day.
		 *
		 * @param playedAt
		 */
		localDay(playedAt) {
			const d = this.parseUtc(playedAt)
			if (Number.isNaN(d.getTime())) { return String(playedAt).slice(0, 10) }
			const pad = (n) => String(n).padStart(2, '0')
			return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
		},

		formatDateTime(playedAt) {
			const date = this.parseUtc(playedAt)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
		},

		formatDate(playedAt) {
			const date = this.parseUtc(playedAt)
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
	max-height: 193px;
}

.date-range {
	margin: 2px 0 0;
	font-size: 12px;
	color: var(--color-text-lighter);
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
	// halo so the number stays readable where it meets the best-score line
	paint-order: stroke;
	stroke: var(--color-background-hover);
	stroke-width: 3px;
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
