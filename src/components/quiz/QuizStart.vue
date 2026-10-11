<template>
	<div class="quiz-start">
		<p v-if="!maxQuestions" class="quiz-start-empty">
			{{ t('moviedb', 'Add some titles to your library to play the quiz.') }}
		</p>
		<template v-else>
			<p class="quiz-start-hint">
				{{ t('moviedb', 'Your library has {count} titles, so you can answer up to {max} questions.', { count: titleCount, max: maxQuestions }) }}
			</p>
			<h3>{{ t('moviedb', 'Number of questions') }}</h3>
			<div class="quiz-start-presets">
				<NcButton
					v-for="preset in presets"
					:key="preset"
					:variant="count === preset ? 'primary' : 'secondary'"
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
				variant="primary"
				:disabled="loading"
				@click="$emit('start', count)">
				{{ t('moviedb', 'Start quiz') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'

export default {
	name: 'QuizStart',

	components: { NcButton, NcTextField },

	props: {
		maxQuestions: { type: Number, default: 0 },
		titleCount: { type: Number, default: 0 },
		loading: { type: Boolean, default: false },
	},

	emits: ['start'],

	data() {
		return {
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
	max-width: 420px;

	h3 {
		margin: 8px 0 0;
	}
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
</style>
