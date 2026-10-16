<template>
	<div class="episode-progress" :class="{ complete }">
		<div
			class="track"
			role="progressbar"
			aria-valuemin="0"
			aria-valuemax="100"
			:aria-valuenow="percent"
			:aria-valuetext="label"
			:aria-label="t('moviedb', 'Episode progress')">
			<div class="fill" :style="{ width: percent + '%' }" />
		</div>
		<!-- The count is also shown as text, so the bar never carries the information alone. -->
		<span class="count" aria-hidden="true">{{ watched }}/{{ aired }}</span>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * Episode progress of a TV show: a bar on a solid track (not on the poster,
 * so its contrast never depends on the image) plus the count as text.
 */
export default {
	name: 'EpisodeProgress',

	props: {
		/** Watched aired episodes */
		watched: {
			type: Number,
			required: true,
		},

		/** Aired episodes (specials excluded) */
		aired: {
			type: Number,
			required: true,
		},
	},

	computed: {
		percent() {
			if (this.aired <= 0) { return 0 }
			return Math.min(100, Math.round((this.watched / this.aired) * 100))
		},

		complete() {
			return this.aired > 0 && this.watched >= this.aired
		},

		label() {
			return t('moviedb', '{watched} of {aired} episodes watched', { watched: this.watched, aired: this.aired })
		},
	},

	methods: {
		t,
	},
}
</script>

<style lang="scss" scoped>
.episode-progress {
    display: flex;
    align-items: center;
    gap: 6px;
}

.track {
    flex: 1;
    height: 8px;
    border-radius: 4px;
    // A max-contrast border keeps the empty part visible on any background (WCAG 1.4.11).
    border: 1px solid var(--color-border-maxcontrast);
    background: var(--color-main-background);
    overflow: hidden;
}

.fill {
    height: 100%;
    background: var(--color-primary-element);
}

.complete .fill {
    // --color-success is a light background tone; the element variant has enough contrast.
    background: var(--color-element-success, var(--color-primary-element));
}

.count {
    flex-shrink: 0;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
    color: var(--color-text-maxcontrast);
}
</style>
