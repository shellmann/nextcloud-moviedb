<template>
	<div class="platform-chart">
		<p v-if="!data.length" class="empty-message">
			{{ t('moviedb', 'No watch history yet.') }}
		</p>
		<div v-else class="platform-rows">
			<div v-for="platform in data" :key="platform.id" class="platform-row">
				<span class="platform-name">{{ platform.name }}</span>
				<div class="bar-track">
					<div
						class="bar-fill"
						:style="{ width: barWidthPercent(platform.count) + '%' }" />
				</div>
				<span class="platform-count">{{ platform.count }}</span>
			</div>
		</div>
	</div>
</template>

<script>
/**
 * PlatformChart component - Renders watch counts per streaming platform as
 * horizontal bars.
 */
export default {
	name: 'PlatformChart',

	props: {
		/**
		 * Platforms with their watch counts, sorted descending by count.
		 *
		 * @type {Array<{id: number, name: string, count: number}>}
		 */
		data: {
			type: Array,
			required: true,
		},
	},

	computed: {
		maxCount() {
			return this.data.reduce((max, p) => Math.max(max, p.count), 0)
		},
	},

	methods: {
		barWidthPercent(count) {
			return this.maxCount > 0 ? (count / this.maxCount) * 100 : 0
		},
	},
}
</script>

<style lang="scss" scoped>
.platform-chart {
    width: 100%;
}

.platform-rows {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.platform-row {
    display: grid;
    grid-template-columns: 120px 1fr 32px;
    align-items: center;
    gap: 8px;
}

.platform-name {
    font-size: 13px;
    color: var(--color-main-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.bar-track {
    background-color: var(--color-primary-element-light);
    border-radius: 8px;
    height: 16px;
    overflow: hidden;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1);
}

.bar-fill {
    background: linear-gradient(90deg, color-mix(in srgb, var(--color-primary-element) 70%, transparent), var(--color-primary-element));
    height: 100%;
    border-radius: 8px;
    transition: width 0.5s ease;
}

.platform-count {
    font-size: 13px;
    color: var(--color-text-lighter);
    text-align: right;
}

.empty-message {
    color: var(--color-text-lighter);
    text-align: center;
    padding: 20px 0;
}
</style>
