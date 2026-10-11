<template>
	<section class="quiz-summary" aria-labelledby="quiz-summary-heading">
		<h3
			id="quiz-summary-heading"
			ref="heading"
			tabindex="-1">
			{{ t('moviedb', 'Quiz finished') }}
		</h3>

		<div class="quiz-summary-score">
			<svg
				class="quiz-ring"
				viewBox="0 0 120 120"
				role="img"
				:aria-label="t('moviedb', 'Score: {percent}%', { percent: result.percent })">
				<circle
					class="quiz-ring-track"
					cx="60"
					cy="60"
					:r="radius" />
				<circle
					class="quiz-ring-value"
					cx="60"
					cy="60"
					:r="radius"
					:stroke-dasharray="circumference"
					:stroke-dashoffset="isMounted ? offset : circumference" />
				<text
					x="60"
					y="68"
					text-anchor="middle"
					class="quiz-ring-text"
					aria-hidden="true">{{ result.percent }}%</text>
			</svg>
			<div class="quiz-summary-text">
				<p class="quiz-summary-points">
					{{ t('moviedb', '{score} of {max} points', { score: result.score, max: result.maxScore }) }}
				</p>
				<p class="quiz-summary-message">
					{{ message }}
				</p>
				<p v-if="isNewBest" class="quiz-summary-best">
					<TrophyOutline :size="20" />
					{{ t('moviedb', 'New personal best!') }}
				</p>
			</div>
		</div>

		<h4>{{ t('moviedb', 'Accuracy by question type') }}</h4>
		<QuizAccuracyList :accuracy="accuracy" />

		<NcButton variant="primary" size="large" @click="$emit('playAgain')">
			<template #icon>
				<Replay :size="22" />
			</template>
			{{ t('moviedb', 'Play again') }}
		</NcButton>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import Replay from 'vue-material-design-icons/Replay.vue'
import TrophyOutline from 'vue-material-design-icons/TrophyOutline.vue'
import QuizAccuracyList from './QuizAccuracyList.vue'

const RADIUS = 52

export default {
	name: 'QuizSummary',

	components: { NcButton, QuizAccuracyList, Replay, TrophyOutline },

	props: {
		/** @type {{score: number, maxScore: number, percent: number, breakdown: Object<string, {points: number, max: number}>}} */
		result: { type: Object, required: true },
		isNewBest: { type: Boolean, default: false },
	},

	emits: ['playAgain'],

	data() {
		return { isMounted: false, radius: RADIUS, circumference: 2 * Math.PI * RADIUS }
	},

	computed: {
		offset() {
			return this.circumference * (1 - this.result.percent / 100)
		},

		accuracy() {
			const accuracy = {}
			for (const [type, entry] of Object.entries(this.result.breakdown)) {
				accuracy[type] = Math.round(entry.points * 100 / entry.max)
			}
			return accuracy
		},

		/** A short, friendly verdict for the score. */
		message() {
			const percent = this.result.percent
			if (percent >= 90) { return t('moviedb', 'Outstanding! You really know your library.') }
			if (percent >= 70) { return t('moviedb', 'Great job! You know your library well.') }
			if (percent >= 40) { return t('moviedb', 'Not bad — keep watching!') }
			return t('moviedb', 'Time for some rewatching?')
		},
	},

	mounted() {
		// Land on the result, not on <body>, and let the ring fill in.
		this.$nextTick(() => this.$refs.heading?.focus?.({ preventScroll: true }))
		requestAnimationFrame(() => {
			this.isMounted = true
		})
	},
}
</script>

<style lang="scss" scoped>
.quiz-summary {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 16px;
	max-width: 560px;
	padding: 24px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);

	h3,
	h4 {
		margin: 0;
	}

	h3:focus {
		outline: none;
	}

	h3:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 4px;
		border-radius: 4px;
	}
}

.quiz-summary-score {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 24px;
}

.quiz-ring {
	width: 160px;
	height: 160px;
	transform: rotate(-90deg);
}

.quiz-ring-track,
.quiz-ring-value {
	fill: none;
	stroke-width: 10;
}

.quiz-ring-track {
	stroke: var(--color-background-darker);
}

.quiz-ring-value {
	stroke: var(--color-primary-element);
	stroke-linecap: round;
	transition: stroke-dashoffset 0.9s ease;
}

.quiz-ring-text {
	// the ring is rotated -90deg, rotate the label back
	transform: rotate(90deg);
	transform-origin: 60px 60px;
	font-size: 26px;
	font-weight: bold;
	fill: var(--color-main-text);
}

.quiz-summary-text {
	display: flex;
	flex-direction: column;
	gap: 6px;

	p {
		margin: 0;
	}
}

.quiz-summary-points {
	font-size: 22px;
	font-weight: bold;
}

.quiz-summary-message {
	color: var(--color-text-maxcontrast);
}

.quiz-summary-best {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-weight: bold;
	color: var(--color-success-text);
}

@media (max-width: 480px) {
	.quiz-summary {
		padding: 16px;
	}
}

@media (prefers-reduced-motion: reduce) {
	.quiz-ring-value {
		transition: none;
	}
}
</style>
