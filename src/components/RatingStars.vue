<template>
	<div class="rating-stars">
		<span v-for="star in max"
			:key="star"
			class="star"
			:class="{ filled: star <= rating, interactive: !readonly }"
			@click="!readonly && $emit('update', star)">
			<Star v-if="star <= rating" :size="size" />
			<StarOutline v-else :size="size" />
		</span>
	</div>
</template>

<script>
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'

export default {
	name: 'RatingStars',
	components: {
		Star,
		StarOutline,
	},
	props: {
		rating: {
			type: Number,
			default: 0,
		},
		max: {
			type: Number,
			default: 10,
		},
		size: {
			type: Number,
			default: 20,
		},
		readonly: {
			type: Boolean,
			default: false,
		},
	},
}
</script>

<style lang="scss" scoped>
.rating-stars {
    display: inline-flex;
    gap: 2px;
}

.star {
    color: var(--color-text-lighter);

    &.filled {
        color: #f1c40f;
    }

    &.interactive {
        cursor: pointer;

        &:hover {
            color: #f1c40f;
        }
    }
}
</style>
