<template>
	<section class="quiz-question" :aria-labelledby="promptId">
		<div class="quiz-header">
			<p class="quiz-step">
				{{ t('moviedb', 'Question {current} of {total}', { current: index + 1, total }) }}
			</p>
			<span class="quiz-worth">{{ t('moviedb', 'Worth up to {max} points', { max: maxPoints }) }}</span>
		</div>
		<div
			class="quiz-progress"
			role="progressbar"
			aria-valuemin="1"
			:aria-valuemax="total"
			:aria-valuenow="index + 1"
			:aria-valuetext="t('moviedb', 'Question {current} of {total}', { current: index + 1, total })"
			:aria-label="t('moviedb', 'Quiz progress')">
			<span class="quiz-progress-fill" :style="{ width: progress + '%' }" />
		</div>

		<h3
			:id="promptId"
			ref="prompt"
			class="quiz-prompt"
			tabindex="-1">
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
			:aria-labelledby="promptId">
			<button
				v-for="(option, i) in question.options"
				:key="option.key"
				type="button"
				class="quiz-option"
				:class="optionClass(option)"
				:disabled="!!answer"
				@click="$emit('answer', option.key)">
				<span class="quiz-option-letter" aria-hidden="true">{{ letters[i] }}</span>
				<img
					v-if="option.posterPath"
					class="quiz-option-poster"
					:src="getPosterUrl(option.posterPath, 'w154')"
					alt="">
				<span class="quiz-option-label">{{ option.label }}</span>
				<template v-if="answer">
					<Check
						v-if="option.key === question.answer"
						class="quiz-option-icon is-correct"
						:size="24" />
					<Close
						v-else-if="option.key === String(answer.value)"
						class="quiz-option-icon is-wrong"
						:size="24" />
					<span v-if="option.key === question.answer" class="sr-only">{{ t('moviedb', 'Correct answer') }}</span>
					<span v-else-if="option.key === String(answer.value)" class="sr-only">{{ t('moviedb', 'Your answer') }}</span>
				</template>
			</button>
		</div>

		<div v-else class="quiz-estimate">
			<!-- The slider announces its own value; the big number is for sighted users. -->
			<div class="quiz-estimate-value" aria-hidden="true">
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
			<p class="quiz-scoring-hint">
				{{ scoringHint }}
			</p>
			<NcButton v-if="!answer" variant="primary" @click="$emit('answer', estimate)">
				{{ t('moviedb', 'Answer') }}
			</NcButton>
		</div>

		<div
			v-if="answer"
			:id="feedbackId"
			class="quiz-feedback"
			:class="feedbackClass"
			role="status">
			<Check v-if="feedbackClass === 'is-correct'" class="quiz-feedback-icon" :size="28" />
			<Close v-else-if="feedbackClass === 'is-wrong'" class="quiz-feedback-icon" :size="28" />
			<ArrowRightThin v-else class="quiz-feedback-icon" :size="28" />
			<div class="quiz-feedback-text">
				<strong>{{ feedbackTitle }}</strong>
				<span>{{ t('moviedb', 'The correct answer was {answer}.', { answer: correctAnswerLabel }) }}</span>
				<span class="quiz-points">{{ t('moviedb', '+{points} points', { points: answer.points }) }}</span>
			</div>
			<NcButton
				ref="nextButton"
				variant="primary"
				:aria-describedby="feedbackId"
				@click="$emit('next')">
				{{ isLast ? t('moviedb', 'Show result') : t('moviedb', 'Next question') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import ArrowRightThin from 'vue-material-design-icons/ArrowRightThin.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { getPosterUrl } from '../../composables/usePosterUrl.js'
import { MAX_POINTS, NUMBER_POINTS, YEAR_POINTS } from '../../utils/quizScoring.js'

export default {
	name: 'QuizQuestion',

	components: { NcButton, ArrowRightThin, Check, Close, Minus, Plus },

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
			letters: ['A', 'B', 'C', 'D'],
			maxPoints: MAX_POINTS,
		}
	},

	computed: {
		promptId() {
			return `quiz-prompt-${this.index}`
		},

		feedbackId() {
			return `quiz-feedback-${this.index}`
		},

		progress() {
			return Math.round((this.index + 1) / this.total * 100)
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

		/** How an estimate is rated, shown under the slider. */
		scoringHint() {
			if (this.question.mode === 'year') {
				const [exact, one, two, three] = YEAR_POINTS
				return t('moviedb', 'Exact: {exact} points · 1 year off: {one} · 2 years off: {two} · 3 years off: {three}', { exact, one, two, three })
			}
			return t('moviedb', 'Exact: {exact} points · 1 off: {one}', { exact: NUMBER_POINTS[0], one: NUMBER_POINTS[1] })
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

	watch: {
		// After answering, the clicked option is disabled and loses focus: move
		// it to "Next" so keyboard and screen reader users keep their place.
		answer(value) {
			if (value) {
				this.$nextTick(() => this.$refs.nextButton?.$el?.focus?.())
			}
		},
	},

	mounted() {
		// A new question replaces the old DOM, which would drop focus to <body>.
		this.$nextTick(() => this.$refs.prompt?.focus?.({ preventScroll: true }))
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
	gap: 14px;
	max-width: 680px;
	padding: 24px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.quiz-header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px 12px;
}

.quiz-step {
	margin: 0;
	font-weight: 600;
}

.quiz-worth {
	white-space: nowrap;
	padding: 2px 10px;
	border-radius: 12px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 13px;
}

.quiz-progress {
	height: 8px;
	overflow: hidden;
	border-radius: 4px;
	background: var(--color-background-darker);
}

.quiz-progress-fill {
	display: block;
	height: 100%;
	border-radius: 4px;
	background: var(--color-primary-element);
	transition: width 0.4s ease;
}

.quiz-prompt {
	margin: 8px 0 0;
	font-size: 22px;
	line-height: 1.3;

	&:focus {
		outline: none;
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 4px;
		border-radius: 4px;
	}
}

.quiz-poster {
	width: 200px;
	max-width: 100%;
	border-radius: 8px;
	box-shadow: 0 2px 10px var(--color-box-shadow);

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
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 10px;
}

.quiz-option {
	display: flex;
	align-items: center;
	gap: 12px;
	min-height: 56px;
	padding: 8px 14px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
	transition: transform 0.15s ease, border-color 0.15s ease;

	&:hover:not(:disabled) {
		transform: translateY(-1px);
		border-color: var(--color-primary-element);
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}

	// After answering: neutral options fade back, the two that matter stand out
	// (border + tint + icon + hidden text, never colour alone).
	&:disabled {
		cursor: default;
		color: var(--color-main-text);
		background: var(--color-main-background);
		opacity: 0.7;
	}

	&.is-correct,
	&.is-wrong {
		opacity: 1;
		border-width: 3px;
	}

	&.is-correct {
		border-color: var(--color-success-text);
		background: color-mix(in srgb, var(--color-success) 16%, var(--color-main-background));
	}

	&.is-wrong {
		border-color: var(--color-error-text);
		background: color-mix(in srgb, var(--color-error) 14%, var(--color-main-background));
	}
}

.quiz-option-letter {
	display: inline-flex;
	flex-shrink: 0;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: var(--color-background-dark);
	font-weight: 700;
	font-size: 14px;
}

.quiz-option-label {
	flex: 1;
	font-weight: 600;
	overflow-wrap: anywhere;
}

.quiz-option-poster {
	width: 48px;
	border-radius: 4px;
}

.quiz-option-icon {
	flex-shrink: 0;

	&.is-correct {
		color: var(--color-success-text);
	}

	&.is-wrong {
		color: var(--color-error-text);
	}
}

.quiz-estimate {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
}

.quiz-estimate-value {
	min-width: 140px;
	padding: 4px 24px;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	font-size: 44px;
	font-weight: bold;
	font-variant-numeric: tabular-nums;
	text-align: center;
}

.quiz-estimate-controls {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
}

.quiz-range {
	flex: 1;
	min-height: 44px;
	accent-color: var(--color-primary-element);
}

.quiz-scoring-hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.quiz-feedback {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	padding: 14px 16px;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	border-inline-start: 6px solid var(--color-border-maxcontrast);

	&.is-correct {
		border-color: var(--color-success-text);
	}

	&.is-close {
		border-color: var(--color-warning-text);
	}

	&.is-wrong {
		border-color: var(--color-error-text);
	}

	&.is-correct .quiz-feedback-icon {
		color: var(--color-success-text);
	}

	&.is-close .quiz-feedback-icon {
		color: var(--color-warning-text);
	}

	&.is-wrong .quiz-feedback-icon {
		color: var(--color-error-text);
	}
}

.quiz-feedback-text {
	display: flex;
	flex: 1;
	flex-direction: column;
	gap: 2px;
	min-width: 200px;
}

.quiz-points {
	color: var(--color-text-maxcontrast);
}

.sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}

@media (max-width: 480px) {
	.quiz-question {
		padding: 16px;
	}
}

@media (prefers-reduced-motion: reduce) {
	.quiz-option,
	.quiz-progress-fill {
		transition: none;
	}

	.quiz-option:hover:not(:disabled) {
		transform: none;
	}
}
</style>
