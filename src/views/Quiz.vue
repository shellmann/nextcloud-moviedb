<template>
	<div class="quiz">
		<div class="quiz-title">
			<h2>
				<TrophyOutline :size="28" aria-hidden="true" />
				{{ t('moviedb', 'Quiz') }}
			</h2>
			<NcButton
				v-if="quizStore.phase === 'playing'"
				variant="tertiary"
				@click="showQuitDialog = true">
				<template #icon>
					<ExitToApp :size="20" />
				</template>
				{{ t('moviedb', 'Quit quiz') }}
			</NcButton>
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

		<section v-if="quizStore.phase !== 'playing'" class="quiz-history" aria-labelledby="quiz-history-heading">
			<h3 id="quiz-history-heading">
				{{ t('moviedb', 'Your results') }}
			</h3>
			<QuizHistoryChart
				:rounds="quizStore.results.rounds"
				:best="bestPercent"
				:firstNumber="firstRoundNumber" />
			<p v-if="isCut" class="quiz-chart-caption">
				{{ t('moviedb', 'Showing your latest {shown} of {total} rounds. Your personal best counts all of them.', { shown: quizStore.results.rounds.length, total: quizStore.results.total }) }}
			</p>
			<p v-if="quizStore.results.rounds.length" class="quiz-chart-caption">
				{{ t('moviedb', 'Each bar is the share of the maximum points you scored in that round.') }}
			</p>
			<p v-if="bestPercent !== null" class="quiz-best">
				<TrophyOutline :size="20" aria-hidden="true" />
				{{ t('moviedb', 'Personal best: {percent}%', { percent: bestPercent }) }}
			</p>
			<template v-if="hasAccuracy">
				<h4>{{ t('moviedb', 'Accuracy by question type') }}</h4>
				<QuizAccuracyList :accuracy="quizStore.results.accuracy" />
			</template>
			<NcButton
				v-if="quizStore.results.rounds.length"
				class="quiz-reset"
				variant="secondary"
				@click="showResetDialog = true">
				<template #icon>
					<DeleteOutline :size="20" />
				</template>
				{{ t('moviedb', 'Reset quiz history') }}
			</NcButton>
		</section>

		<NcDialog
			:open="showQuitDialog"
			:name="t('moviedb', 'Quit this round?')"
			@update:open="showQuitDialog = $event">
			<p>{{ t('moviedb', 'Your answers so far will not be saved.') }}</p>
			<template #actions>
				<NcButton @click="showQuitDialog = false">
					{{ t('moviedb', 'Keep playing') }}
				</NcButton>
				<NcButton variant="error" @click="quit">
					{{ t('moviedb', 'Quit quiz') }}
				</NcButton>
			</template>
		</NcDialog>

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
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import ExitToApp from 'vue-material-design-icons/ExitToApp.vue'
import TrophyOutline from 'vue-material-design-icons/TrophyOutline.vue'
import QuizAccuracyList from '../components/quiz/QuizAccuracyList.vue'
import QuizHistoryChart from '../components/quiz/QuizHistoryChart.vue'
import QuizQuestion from '../components/quiz/QuizQuestion.vue'
import QuizStart from '../components/quiz/QuizStart.vue'
import QuizSummary from '../components/quiz/QuizSummary.vue'
import { useLibrariesStore } from '../stores/libraries.js'
import { useQuizStore } from '../stores/quiz.js'

export default {
	name: 'Quiz',

	components: {
		NcButton,
		NcDialog,
		DeleteOutline,
		ExitToApp,
		QuizAccuracyList,
		QuizHistoryChart,
		QuizQuestion,
		QuizStart,
		QuizSummary,
		TrophyOutline,
	},

	setup() {
		const quizStore = useQuizStore()
		const librariesStore = useLibrariesStore()
		return { quizStore, librariesStore }
	},

	data() {
		return {
			showResetDialog: false,
			showQuitDialog: false,
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

		/** The history only loads the latest rounds; number them from where they start. */
		firstRoundNumber() {
			const { rounds, total } = this.quizStore.results
			return Math.max(1, total - rounds.length + 1)
		},

		isCut() {
			return this.quizStore.results.total > this.quizStore.results.rounds.length
		},

		hasAccuracy() {
			return Object.keys(this.quizStore.results.accuracy).length > 0
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

		quit() {
			this.showQuitDialog = false
			this.quizStore.reset()
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
	gap: 20px;
	padding: 20px;
	max-width: 760px;
}

.quiz-title {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;

	h2 {
		display: flex;
		align-items: center;
		gap: 10px;
		margin: 0;
	}
}

@media (max-width: 480px) {
	.quiz {
		padding: 12px;
	}

	.quiz-history {
		padding: 16px;
	}
}

.quiz-history {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 10px;
	margin-top: 8px;
	padding: 24px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);

	h3,
	h4 {
		margin: 0;
	}

	h4 {
		margin-top: 8px;
	}
}

.quiz-reset {
	margin-top: 12px;
	border: 1px solid var(--color-border-maxcontrast);
}

.quiz-chart-caption {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.quiz-best {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin: 0;
	color: var(--color-success-text);
	font-weight: bold;
}
</style>
