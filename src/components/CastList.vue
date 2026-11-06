<template>
	<section v-if="visibleCast.length" class="cast-section" :aria-labelledby="headingId">
		<h3 :id="headingId">
			{{ t('moviedb', 'Cast') }}
		</h3>
		<ul class="cast-list">
			<li v-for="actor in visibleCast" :key="actor.name" class="cast-item">
				<strong>{{ actor.name }}</strong>
				<span v-if="actor.character" class="cast-character">{{ actor.character }}</span>
			</li>
		</ul>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

let uid = 0

export default {
	name: 'CastList',
	props: {
		cast: { type: Array, default: () => [] },
		limit: { type: Number, default: 6 },
	},

	data() {
		return { headingId: `cast-heading-${++uid}` }
	},

	computed: {
		visibleCast() {
			return (this.cast || []).filter((a) => a && a.name).slice(0, this.limit)
		},
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
.cast-section h3 {
    margin: 0 0 12px;
    font-size: 1.1em;
}

.cast-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
    list-style: none;
    margin: 0;
    padding: 0;
}

.cast-item {
    strong {
        display: block;
        font-size: 14px;
    }

    .cast-character {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
    }
}
</style>
