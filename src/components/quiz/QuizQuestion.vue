<template>
	<div class="quiz-question">
		<NcProgressBar :value="progress" size="medium" />
		<p class="quiz-progress-text">
			{{ t('moviedb', 'Question {current} of {total}', { current: index + 1, total }) }}
		</p>

		<h3 class="quiz-prompt">
			{{ prompt }}
		</h3>

		<!-- The poster stays blurred until answered; the title is never shown before. -->
		<img
			v-if="question.type === 'poster' && question.subject.posterPath"
			class="quiz-poster"
			:class="{ blurred: !answer }"
			:src="getPosterUrl(question.subject.posterPath)"
			:alt="answer ? question.subject.title : t('moviedb', 'Blurred poster')">

		<blockquote v-if="question.type === 'review'" class="quiz-review">
			{{ question.extra.review }}
		</blockquote>

		<div
			v-if="question.mode === 'choice'"
			class="quiz-options"
			role="group"
			:aria-label="prompt">
			<button
				v-for="option in question.options"
				:key="option.key"
				type="button"
				class="quiz-option"
				:class="optionClass(option)"
				:disabled="!!answer"
				@click="$emit('answer', option.key)">
				<img
					v-if="option.posterPath"
					class="quiz-option-poster"
					:src="getPosterUrl(option.posterPath, 'w154')"
					alt="">
				<span>{{ option.label }}</span>
			</button>
		</div>

		<div v-else class="quiz-estimate">
			<div class="quiz-estimate-value" aria-live="polite">
				{{ estimate }}
			</div>
			<div class="quiz-estimate-controls">
				<NcButton
					:aria-label="t('moviedb', 'Decrease')"
					:disabled="!!answer || estimate <= question.min"
					@click="estimate--">
					<template #icon>
						<Minus :size="20" />
					</template>
				</NcButton>
				<input
					v-model.number="estimate"
					class="quiz-range"
					type="range"
					:min="question.min"
					:max="question.max"
					step="1"
					:disabled="!!answer"
					:aria-label="t('moviedb', 'Your estimate')">
				<NcButton
					:aria-label="t('moviedb', 'Increase')"
					:disabled="!!answer || estimate >= question.max"
					@click="estimate++">
					<template #icon>
						<Plus :size="20" />
					</template>
				</NcButton>
			</div>
			<NcButton v-if="!answer" variant="primary" @click="$emit('answer', estimate)">
				{{ t('moviedb', 'Answer') }}
			</NcButton>
		</div>

		<div
			v-if="answer"
			class="quiz-feedback"
			:class="feedbackClass"
			role="status">
			<strong>{{ feedbackTitle }}</strong>
			<span>{{ t('moviedb', 'The correct answer was {answer}.', { answer: correctAnswerLabel }) }}</span>
			<span class="quiz-points">{{ t('moviedb', '+{points} points', { points: answer.points }) }}</span>
			<NcButton variant="primary" @click="$emit('next')">
				{{ isLast ? t('moviedb', 'Show result') : t('moviedb', 'Next question') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcProgressBar } from '@nextcloud/vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { getPosterUrl } from '../../composables/usePosterUrl.js'
import { MAX_POINTS } from '../../utils/quizScoring.js'

export default {
	name: 'QuizQuestion',

	components: { NcButton, NcProgressBar, Minus, Plus },

	props: {
		question: { type: Object, required: true },
		index: { type: Number, required: true },
		total: { type: Number, required: true },
		/** The given answer ({ value, points }), or null while unanswered. */
		answer: { type: Object, default: null },
	},

	emits: ['answer', 'next'],

	data() {
		return {
			estimate: this.initialEstimate(),
		}
	},

	computed: {
		progress() {
			return Math.round(this.index / this.total * 100)
		},

		isLast() {
			return this.index >= this.total - 1
		},

		prompt() {
			const title = this.question.subject.title
			switch (this.question.type) {
				case 'release_year':
					return this.question.subject.mediaType === 'series'
						? t('moviedb', 'In which year did "{title}" first air?', { title })
						: t('moviedb', 'In which year was "{title}" released?', { title })
				case 'watch_year': return t('moviedb', 'In which year did you first watch "{title}"?', { title })
				case 'director': return t('moviedb', 'Who directed "{title}"?', { title })
				case 'actor': return t('moviedb', 'Which of these actors appears in "{title}"?', { title })
				case 'watched_first': return t('moviedb', 'Which of these did you watch first?')
				case 'poster': return t('moviedb', 'Which title does this poster belong to?')
				case 'rating': return t('moviedb', 'Which rating did you give "{title}"?', { title })
				case 'review': return t('moviedb', 'Which title did you write this review about?')
				case 'seasons': return t('moviedb', 'How many seasons does "{title}" have?', { title })
				default: return ''
			}
		},

		correctAnswerLabel() {
			if (this.question.mode === 'choice') {
				return this.question.options.find((o) => o.key === this.question.answer)?.label ?? ''
			}
			return String(this.question.answer)
		},

		feedbackClass() {
			if (this.answer.points === MAX_POINTS) { return 'is-correct' }
			return this.answer.points > 0 ? 'is-close' : 'is-wrong'
		},

		feedbackTitle() {
			if (this.answer.points === MAX_POINTS) { return t('moviedb', 'Correct!') }
			return this.answer.points > 0 ? t('moviedb', 'Close!') : t('moviedb', 'Not quite.')
		},
	},

	methods: {
		getPosterUrl,

		/**
		 * Start in the middle of the range, but never exactly on the answer.
		 *
		 * @return {number}
		 */
		initialEstimate() {
			const { min, max, answer, mode } = this.question
			if (mode === 'choice') { return 0 }
			const middle = Math.round((min + max) / 2)
			if (middle !== answer) { return middle }
			return middle + 1 <= max ? middle + 1 : middle - 1
		},

		optionClass(option) {
			if (!this.answer) { return {} }
			return {
				'is-correct': option.key === this.question.answer,
				'is-wrong': option.key === String(this.answer.value) && option.key !== this.question.answer,
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.quiz-question {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 640px;
}

.quiz-progress-text {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.quiz-prompt {
	margin: 8px 0 0;
}

.quiz-poster {
	width: 200px;
	border-radius: 8px;

	&.blurred {
		filter: blur(12px);
	}
}

.quiz-review {
	margin: 0;
	padding: 8px 16px;
	border-inline-start: 4px solid var(--color-primary-element);
	font-style: italic;
}

.quiz-options {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 8px;
}

.quiz-option {
	display: flex;
	align-items: center;
	gap: 12px;
	min-height: 44px;
	padding: 8px 12px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;

	&:hover:not(:disabled) {
		border-color: var(--color-primary-element);
	}

	&.is-correct {
		border-color: var(--color-success);
		background: var(--color-success);
		color: var(--color-success-text);
	}

	&.is-wrong {
		border-color: var(--color-error);
		background: var(--color-error);
		color: var(--color-error-text);
	}
}

.quiz-option-poster {
	width: 56px;
	border-radius: 4px;
}

.quiz-estimate {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
}

.quiz-estimate-value {
	font-size: 40px;
	font-weight: bold;
}

.quiz-estimate-controls {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
}

.quiz-range {
	flex: 1;
}

.quiz-feedback {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 6px;
	padding: 12px 16px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
	border-inline-start: 4px solid var(--color-border-maxcontrast);

	&.is-correct {
		border-color: var(--color-success);
	}

	&.is-close {
		border-color: var(--color-warning);
	}

	&.is-wrong {
		border-color: var(--color-error);
	}
}

.quiz-points {
	color: var(--color-text-maxcontrast);
}
</style>
