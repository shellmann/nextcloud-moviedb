<template>
	<ul class="quiz-accuracy">
		<li v-for="row in rows" :key="row.type">
			<span class="quiz-accuracy-label">{{ row.label }}</span>
			<span
				class="quiz-accuracy-bar"
				role="meter"
				aria-valuemin="0"
				aria-valuemax="100"
				:aria-valuenow="row.percent"
				:aria-valuetext="row.text"
				:aria-label="row.label">
				<span class="quiz-accuracy-fill" :style="{ width: row.percent + '%' }" />
			</span>
			<span class="quiz-accuracy-value">{{ row.percent }}%</span>
		</li>
	</ul>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { quizTypeLabel } from '../../utils/quizScoring.js'

/**
 * Accuracy per question type as labelled bars. The percentage is always
 * written out as text too, so the bar is never the only carrier of the value.
 */
export default {
	name: 'QuizAccuracyList',

	props: {
		/** @type {Object<string, number>} question type => percent (0..100) */
		accuracy: { type: Object, required: true },
	},

	computed: {
		rows() {
			return Object.entries(this.accuracy).map(([type, percent]) => {
				const label = quizTypeLabel(type)
				return { type, label, percent, text: t('moviedb', '{label}: {percent}%', { label, percent }) }
			})
		},
	},
}
</script>

<style lang="scss" scoped>
.quiz-accuracy {
	display: flex;
	flex-direction: column;
	gap: 10px;
	width: 100%;
	margin: 0;
	padding: 0;
	list-style: none;

	li {
		display: grid;
		grid-template-columns: minmax(110px, 160px) 1fr 48px;
		align-items: center;
		gap: 12px;
	}
}

.quiz-accuracy-label {
	overflow-wrap: anywhere;
}

.quiz-accuracy-bar {
	display: block;
	height: 10px;
	overflow: hidden;
	border-radius: 5px;
	background: var(--color-background-darker);
}

.quiz-accuracy-fill {
	display: block;
	height: 100%;
	border-radius: 5px;
	background: var(--color-primary-element);
	transition: width 0.5s ease;
}

.quiz-accuracy-value {
	text-align: end;
	font-variant-numeric: tabular-nums;
	font-weight: 600;
}

@media (prefers-reduced-motion: reduce) {
	.quiz-accuracy-fill {
		transition: none;
	}
}

@media (max-width: 480px) {
	.quiz-accuracy li {
		grid-template-columns: 1fr 48px;

		.quiz-accuracy-bar {
			grid-column: 1 / -1;
			grid-row: 2;
		}
	}
}
</style>
