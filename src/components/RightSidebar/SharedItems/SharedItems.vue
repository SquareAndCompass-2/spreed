<!--
  - @copyright Copyright (c) 2022 Marco Ambrosini <marcoambrosini@icloud.com>
  -
  - @author Marco Ambrosini <marcoambrosini@icloud.com>
  -
  - @license AGPL-3.0-or-later
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
-->

<template>
	<div class="shared-items" :class="{'shared-items__list' : hasListLayout}">
		<template v-for="item in itemsToDisplay">
			<div v-if="isLocation" :key="item.id" class="shared-items__location">
				<Location wide v-bind="item.messageParameters.object" />
			</div>

			<DeckCard v-else-if="isDeckCard"
				:key="item.id"
				wide
				v-bind="item.messageParameters.object" />

			<Poll v-else-if="isPoll"
				:key="item.id"
				:token="token"
				v-bind="item.messageParameters.object"
				:poll-name="item.messageParameters.object.name" />

			<div v-else-if="isOther"
				:key="item.id"
				class="shared-items__other">
				<a v-if="item.messageParameters.object.link"
					:href="item.messageParameters.object.link"
					target="_blank">
					{{ item.messageParameters.object.name }}
				</a>
				<p v-else>
					{{ item.messageParameters.object.name }}
				</p>
			</div>

			<FilePreview v-else
				:key="item.id"
				:token="token"
				:small-preview="!isMedia"
				:row-layout="!isMedia"
				:item-type="type"
				is-shared-items
				v-bind="item.messageParameters.file" />
		</template>
	</div>
</template>

<script>
import DeckCard from '../../MessagesList/MessagesGroup/Message/MessagePart/DeckCard.vue'
import FilePreview from '../../MessagesList/MessagesGroup/Message/MessagePart/FilePreview.vue'
import Location from '../../MessagesList/MessagesGroup/Message/MessagePart/Location.vue'
import Poll from '../../MessagesList/MessagesGroup/Message/MessagePart/Poll.vue'

import { SHARED_ITEM } from '../../../constants.js'

export default {
	name: 'SharedItems',

	components: {
		DeckCard,
		FilePreview,
		Location,
		Poll,
	},

	props: {
		token: {
			type: String,
			required: true,
		},

		type: {
			type: String,
			required: true,
		},

		items: {
			type: Object,
			required: true,
		},

		// Limits the amount of items displayed
		limit: {
			type: Number,
			default: undefined,
		},

		// Whether items are shown directly in SharedItemsTab
		tabView: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		itemsToDisplay() {
			return Object.values(this.items).reverse().slice(0, this.limit)
		},
		isLocation() {
			return this.type === SHARED_ITEM.TYPES.LOCATION
		},
		isDeckCard() {
			return this.type === SHARED_ITEM.TYPES.DECK_CARD
		},
		isPoll() {
			return this.type === SHARED_ITEM.TYPES.POLL
		},
		isOther() {
			return this.type === SHARED_ITEM.TYPES.OTHER
		},
		isMedia() {
			return this.type === SHARED_ITEM.TYPES.MEDIA
		},
		hasListLayout() {
			return !this.isMedia && (this.tabView || (!this.isLocation && !this.isDeckCard && !this.isPoll))
		},
	},
}
</script>

<style lang="scss" scoped>
.shared-items {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	grid-gap: 6px;
	margin: auto;

	&__list {
		grid-template-columns: 1fr;
	}

	&__location {
		height: 150px;
		margin: 4px 0;
	}

	&__other {
		padding-left: 8px;

		a {
			text-decoration: underline;

			&:after {
				content: ' ↗';
			}
		}
	}
}
</style>
