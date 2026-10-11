<template>
	<div class="quiz">
		<div class="list-header">
			<h2>{{ t('moviedb', 'Quiz') }}</h2>
		</div>

		<QuizStart
			v-if="quizStore.phase === 'start'"
			:maxQuestions="quizStore.meta?.maxQuestions ?? 0"
			:titleCount="quizStore.meta?.titleCount ?? 0"
			:loading="quizStore.loading"
			@start="quizStore.start($event)" />

		<QuizQuestion
			v-else-if="quizStore.phase === 'playing' && quizStore.currentQuestion"
			:key="quizStore.index"
			:question="quizStore.currentQuestion"
			:index="quizStore.index"
			:total="quizStore.questions.length"
			:answer="quizStore.currentAnswer"
			@answer="quizStore.answer($event)"
			@next="quizStore.next()" />

		<QuizSummary
			v-else-if="quizStore.phase === 'finished' && quizStore.lastResult"
			:result="quizStore.lastResult"
			:isNewBest="quizStore.isNewBest"
			@playAgain="playAgain" />

		<section v-if="quizStore.phase !== 'playing'" class="quiz-history">
			<h3>{{ t('moviedb', 'Your results') }}</h3>
			<QuizHistoryChart :rounds="quizStore.results.rounds" :best="bestPercent" />
			<p v-if="bestPercent !== null" class="quiz-best">
				{{ t('moviedb', 'Personal best: {percent}%', { percent: bestPercent }) }}
			</p>
			<template v-if="accuracyRows.length">
				<h4>{{ t('moviedb', 'Accuracy by question type') }}</h4>
				<ul class="quiz-accuracy">
					<li v-for="row in accuracyRows" :key="row.type">
						<span>{{ row.label }}</span>
						<span>{{ row.percent }}%</span>
					</li>
				</ul>
			</template>
			<NcButton
				v-if="quizStore.results.rounds.length"
				variant="tertiary"
				@click="showResetDialog = true">
				{{ t('moviedb', 'Reset quiz history') }}
			</NcButton>
		</section>

		<NcDialog
			:open="showResetDialog"
			:name="t('moviedb', 'Reset quiz history')"
			@update:open="showResetDialog = $event">
			<p>{{ t('moviedb', 'This deletes all your quiz results for this library. This cannot be undone.') }}</p>
			<template #actions>
				<NcButton :disabled="resetting" @click="showResetDialog = false">
					{{ t('moviedb', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="resetting"
					@click="confirmReset">
					{{ t('moviedb', 'Reset') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import QuizHistoryChart from '../components/quiz/QuizHistoryChart.vue'
import QuizQuestion from '../components/quiz/QuizQuestion.vue'
import QuizStart from '../components/quiz/QuizStart.vue'
import QuizSummary from '../components/quiz/QuizSummary.vue'
import { useLibrariesStore } from '../stores/libraries.js'
import { useQuizStore } from '../stores/quiz.js'
import { quizTypeLabel } from '../utils/quizScoring.js'

export default {
	name: 'Quiz',

	components: {
		NcButton,
		NcDialog,
		QuizHistoryChart,
		QuizQuestion,
		QuizStart,
		QuizSummary,
	},

	setup() {
		const quizStore = useQuizStore()
		const librariesStore = useLibrariesStore()
		return { quizStore, librariesStore }
	},

	data() {
		return {
			showResetDialog: false,
			resetting: false,
		}
	},

	computed: {
		activeLibraryId() {
			return this.librariesStore.activeLibraryId
		},

		bestPercent() {
			return this.quizStore.results.best ? this.quizStore.results.best.percent : null
		},

		accuracyRows() {
			return Object.entries(this.quizStore.results.accuracy).map(([type, percent]) => ({
				type,
				label: quizTypeLabel(type),
				percent,
			}))
		},
	},

	watch: {
		// A running round belongs to one library: drop it when the library changes.
		activeLibraryId() {
			this.quizStore.reset()
			this.load()
		},
	},

	async created() {
		// A fresh visit always starts at the start screen (the round is not kept).
		this.quizStore.reset()
		await this.load()
	},

	methods: {
		async load() {
			await this.librariesStore.whenReady()
			await Promise.all([this.quizStore.fetchMeta(), this.quizStore.fetchResults()])
		},

		async playAgain() {
			this.quizStore.reset()
			// The cap may have changed (titles added or removed since).
			await this.quizStore.fetchMeta()
		},

		async confirmReset() {
			this.resetting = true
			await this.quizStore.resetResults()
			this.resetting = false
			this.showResetDialog = false
		},
	},
}
</script>

<style lang="scss" scoped>
.quiz {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
}

.list-header h2 {
	margin: 0;
}

.quiz-history {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	max-width: 640px;
	margin-top: 16px;

	h3,
	h4 {
		margin: 0;
	}
}

.quiz-best {
	margin: 0;
	color: var(--color-success-text);
	font-weight: bold;
}

.quiz-accuracy {
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
