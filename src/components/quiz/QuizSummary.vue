<template>
	<div class="quiz-summary">
		<h3>{{ t('moviedb', 'Quiz finished') }}</h3>
		<p class="quiz-summary-score">
			{{ t('moviedb', '{score} of {max} points', { score: result.score, max: result.maxScore }) }}
			<span class="quiz-summary-percent">{{ result.percent }}%</span>
		</p>
		<p v-if="isNewBest" class="quiz-summary-best">
			{{ t('moviedb', 'New personal best!') }}
		</p>

		<h4>{{ t('moviedb', 'Accuracy by question type') }}</h4>
		<ul class="quiz-summary-types">
			<li v-for="row in rows" :key="row.type">
				<span>{{ row.label }}</span>
				<span>{{ row.percent }}%</span>
			</li>
		</ul>

		<NcButton variant="primary" @click="$emit('playAgain')">
			{{ t('moviedb', 'Play again') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { quizTypeLabel } from '../../utils/quizScoring.js'

export default {
	name: 'QuizSummary',

	components: { NcButton },

	props: {
		/** @type {{score: number, maxScore: number, percent: number, breakdown: Object<string, {points: number, max: number}>}} */
		result: { type: Object, required: true },
		isNewBest: { type: Boolean, default: false },
	},

	emits: ['playAgain'],

	computed: {
		rows() {
			return Object.entries(this.result.breakdown).map(([type, entry]) => ({
				type,
				label: quizTypeLabel(type),
				percent: Math.round(entry.points * 100 / entry.max),
			}))
		},
	},
}
</script>

<style lang="scss" scoped>
.quiz-summary {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	max-width: 420px;

	h3,
	h4 {
		margin: 0;
	}
}

.quiz-summary-score {
	margin: 0;
	font-size: 24px;
	font-weight: bold;
}

.quiz-summary-percent {
	margin-inline-start: 8px;
	color: var(--color-primary-element);
}

.quiz-summary-best {
	margin: 0;
	font-weight: bold;
	color: var(--color-success-text);
}

.quiz-summary-types {
	width: 100%;
	margin: 0 0 8px;
	padding: 0;
	list-style: none;

	li {
		display: flex;
		justify-content: space-between;
		padding: 4px 0;
		border-bottom: 1px solid var(--color-border);
	}
}
</style>
