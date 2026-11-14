<template>
	<section v-if="watch" class="where-to-watch" :aria-labelledby="headingId">
		<h3 :id="headingId">
			{{ t('moviedb', 'Where to watch') }}
		</h3>
		<p v-if="country" class="region-note">
			{{ t('moviedb', 'Offers in {country}', { country }) }}
		</p>

		<template v-if="groups.length">
			<div v-for="group in groups" :key="group.key" class="provider-group">
				<h4>{{ group.label }}</h4>
				<ul class="provider-list">
					<li v-for="provider in group.items" :key="provider.id" class="provider">
						<img
							v-if="provider.logoPath"
							:src="logoUrl(provider)"
							:alt="provider.name"
							:title="provider.name"
							width="44"
							height="44"
							loading="lazy">
						<span v-else class="provider-name">{{ provider.name }}</span>
					</li>
				</ul>
			</div>
		</template>
		<p v-else class="no-offers">
			{{ country
				? t('moviedb', 'No streaming offers known for {country}.', { country })
				: t('moviedb', 'No streaming offers known.') }}
		</p>

		<p class="sources">
			<a
				v-if="watch.link"
				:href="watch.link"
				target="_blank"
				rel="noopener noreferrer">{{ t('moviedb', 'All offers on TMDB') }}</a>
			<a
				href="https://www.justwatch.com/"
				target="_blank"
				rel="noopener noreferrer">{{ t('moviedb', 'Streaming data from JustWatch') }}</a>
		</p>
	</section>
</template>

<script>
import { getLocale, translate as t } from '@nextcloud/l10n'
import { getPosterUrl } from '../composables/usePosterUrl.js'

let uid = 0

/**
 * "Where to watch": the streaming services of a title in the user's country,
 * with the logos TMDB provides. The data comes from JustWatch (via TMDB), which
 * has to be credited. TMDB gives no links into the services, so the only link
 * is to the title's page on TMDB.
 */
export default {
	name: 'WhereToWatch',
	props: {
		/** @type {{ region: string, link: ?string, stream: object[], free: object[], rent: object[], buy: object[] }|null} */
		watch: { type: Object, default: null },
	},

	data() {
		return { headingId: `where-to-watch-heading-${++uid}` }
	},

	computed: {
		country() {
			const region = this.watch?.region
			if (!region) { return '' }
			try {
				return new Intl.DisplayNames([getLocale().replace('_', '-')], { type: 'region' }).of(region) || region
			} catch {
				return region
			}
		},

		groups() {
			const labels = [
				['stream', t('moviedb', 'Streaming')],
				['free', t('moviedb', 'Free')],
				['rent', t('moviedb', 'Rent')],
				['buy', t('moviedb', 'Buy')],
			]
			return labels
				.map(([key, label]) => ({ key, label, items: this.watch?.[key] || [] }))
				.filter((group) => group.items.length > 0)
		},
	},

	methods: {
		t,
		logoUrl(provider) {
			return getPosterUrl(provider.logoPath, 'w92')
		},
	},
}
</script>

<style scoped lang="scss">
.where-to-watch {
	margin: 24px 0;

	h3 {
		margin: 0 0 4px;
		font-size: 1.1em;
	}

	h4 {
		margin: 12px 0 6px;
		font-size: 14px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}
}

.region-note,
.sources {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.no-offers {
	margin: 12px 0 0;
}

.sources {
	margin-top: 12px;
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;

	a {
		color: var(--color-main-text);
		text-decoration: underline;
	}
}

.provider-list {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.provider img {
	display: block;
	width: 44px;
	height: 44px;
	border-radius: var(--border-radius-large, 12px);
	object-fit: cover;
}

.provider-name {
	font-size: 13px;
}
</style>
