<template>
	<div class="quiz-start">
		<p v-if="!maxQuestions" class="quiz-start-empty">
			{{ t('moviedb', 'Add some titles to your library to play the quiz.') }}
		</p>
		<template v-else>
			<p class="quiz-start-intro">
				{{ t('moviedb', 'How well do you know your own library?') }}
			</p>
			<p class="quiz-start-hint">
				{{ t('moviedb', 'Your library has {count} titles, so you can answer up to {max} questions.', { count: titleCount, max: maxQuestions }) }}
			</p>

			<h3 id="quiz-count-heading">
				{{ t('moviedb', 'How many questions?') }}
			</h3>
			<div class="quiz-start-presets" role="group" aria-labelledby="quiz-count-heading">
				<NcButton
					v-for="preset in presets"
					:key="preset"
					:variant="count === preset ? 'primary' : 'secondary'"
					:aria-pressed="count === preset ? 'true' : 'false'"
					:disabled="preset > maxQuestions"
					@click="count = preset">
					{{ preset }}
				</NcButton>
			</div>
			<NcTextField
				class="quiz-start-input"
				:modelValue="text"
				type="number"
				min="1"
				:max="String(maxQuestions)"
				:label="t('moviedb', 'Number of questions')"
				@update:modelValue="onInput"
				@blur="text = String(count)" />
			<NcButton
				class="quiz-start-button"
				variant="primary"
				size="large"
				:disabled="loading"
				@click="$emit('start', count)">
				<template #icon>
					<Play :size="22" />
				</template>
				{{ t('moviedb', 'Start quiz') }}
			</NcButton>

			<details class="quiz-scoring">
				<summary>{{ t('moviedb', 'How are points calculated?') }}</summary>
				<p>{{ t('moviedb', 'Every question is worth up to {max} points. Your score is the points you earn as a percentage of the maximum.', { max: maxPoints }) }}</p>
				<ul>
					<li>{{ t('moviedb', 'Multiple choice: {max} points for the right answer, otherwise 0.', { max: maxPoints }) }}</li>
					<li>{{ t('moviedb', 'Year estimates: {exact} points if exact, {one} if 1 year off, {two} if 2 years off, {three} if 3 years off.', yearPoints) }}</li>
					<li>{{ t('moviedb', 'Number answers (rating, seasons): {exact} points if exact, {one} if 1 off.', numberPoints) }}</li>
				</ul>
			</details>
		</template>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import Play from 'vue-material-design-icons/Play.vue'
import { MAX_POINTS, NUMBER_POINTS, YEAR_POINTS } from '../../utils/quizScoring.js'

export default {
	name: 'QuizStart',

	components: { NcButton, NcTextField, Play },

	props: {
		maxQuestions: { type: Number, default: 0 },
		titleCount: { type: Number, default: 0 },
		loading: { type: Boolean, default: false },
	},

	emits: ['start'],

	data() {
		return {
			maxPoints: MAX_POINTS,
			yearPoints: { exact: YEAR_POINTS[0], one: YEAR_POINTS[1], two: YEAR_POINTS[2], three: YEAR_POINTS[3] },
			numberPoints: { exact: NUMBER_POINTS[0], one: NUMBER_POINTS[1] },
			presets: [5, 10, 20],
			count: 1,
			// What the field shows. Kept apart from `count` so out-of-range input
			// is not forced back while typing, only when the field loses focus.
			text: '1',
		}
	},

	watch: {
		count(value) {
			this.text = String(value)
		},

		// The cap can change (library switch): keep the choice inside it.
		maxQuestions: {
			immediate: true,
			handler(max) {
				this.count = max > 0 ? this.clamp(this.count === 1 ? 10 : this.count, max) : 1
			},
		},
	},

	methods: {
		clamp(value, max = this.maxQuestions) {
			return Math.max(1, Math.min(max, value))
		},

		onInput(value) {
			this.text = String(value)
			const n = parseInt(value, 10)
			if (Number.isNaN(n)) {
				return
			}
			this.count = this.clamp(n)
		},
	},
}
</script>

<style lang="scss" scoped>
.quiz-start {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
	max-width: 480px;
	padding: 24px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);

	h3 {
		margin: 8px 0 0;
		font-size: 18px;
	}
}

@media (max-width: 480px) {
	.quiz-start {
		padding: 16px;
	}
}

.quiz-start-intro {
	margin: 0;
	font-size: 20px;
	font-weight: bold;
}

.quiz-start-hint,
.quiz-start-empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.quiz-start-presets {
	display: flex;
	gap: 8px;
}

.quiz-start-input {
	max-width: 160px;
}

.quiz-start-button {
	margin-top: 8px;
}

.quiz-scoring {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);

	summary {
		cursor: pointer;
		color: var(--color-main-text);
	}

	p,
	ul {
		margin: 8px 0 0;
	}

	ul {
		padding-inline-start: 20px;
	}
}
</style>
