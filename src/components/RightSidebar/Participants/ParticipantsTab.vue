<!--
  - @copyright Copyright (c) 2019 Marco Ambrosini <marcoambrosini@icloud.com>
  -
  - @author Marco Ambrosini <marcoambrosini@icloud.com>
  -
  - @license GNU AGPL version 3 or any later version
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
	<div class="wrapper">
		<SearchBox v-if="canSearch"
			:value.sync="searchText"
			:is-focused.sync="isFocused"
			:placeholder-text="searchBoxPlaceholder"
			@input="handleInput"
			@abort-search="abortSearch" />

		<ParticipantsListVirtual v-if="!isSearching"
			:participants="participants"
			:loading="!participantsInitialised" />

		<div v-else class="scroller">
			<NcAppNavigationCaption v-if="canAdd" :title="t('spreed', 'Participants')" />

			<ParticipantsList v-if="filteredParticipants.length"
				:items="filteredParticipants"
				:loading="!participantsInitialised" />
			<Hint v-else :hint="t('spreed', 'No search results')" />

			<ParticipantsSearchResults v-if="canAdd"
				:search-results="searchResults"
				:contacts-loading="contactsLoading"
				:no-results="noResults"
				:search-text="searchText"
				@click="addParticipants" />
		</div>
	</div>
</template>

<script>
import debounce from 'debounce'

import { showError } from '@nextcloud/dialogs'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { loadState } from '@nextcloud/initial-state'

import NcAppNavigationCaption from '@nextcloud/vue/dist/Components/NcAppNavigationCaption.js'

import Hint from '../../Hint.vue'
import SearchBox from '../../LeftSidebar/SearchBox/SearchBox.vue'
import ParticipantsList from './ParticipantsList/ParticipantsList.vue'
import ParticipantsListVirtual from './ParticipantsList/ParticipantsListVirtual.vue'
import ParticipantsSearchResults from './ParticipantsSearchResults/ParticipantsSearchResults.vue'

import { useSortParticipants } from '../../../composables/useSortParticipants.js'
import getParticipants from '../../../mixins/getParticipants.js'
import { searchPossibleConversations } from '../../../services/conversationsService.js'
import { EventBus } from '../../../services/EventBus.js'
import { addParticipant } from '../../../services/participantsService.js'
import CancelableRequest from '../../../utils/cancelableRequest.js'

export default {
	name: 'ParticipantsTab',
	components: {
		ParticipantsListVirtual,
		ParticipantsList,
		Hint,
		NcAppNavigationCaption,
		SearchBox,
		ParticipantsSearchResults,
	},

	mixins: [getParticipants],

	props: {
		canSearch: {
			type: Boolean,
			required: true,
		},
		canAdd: {
			type: Boolean,
			required: true,
		},
	},

	setup() {
		const { sortParticipants } = useSortParticipants()

		return {
			sortParticipants,
		}
	},

	data() {
		return {
			searchText: '',
			isFocused: false,
			searchResults: [],
			contactsLoading: false,
			isCirclesEnabled: loadState('spreed', 'circles_enabled'),
			cancelSearchPossibleConversations: () => {},
		}
	},

	computed: {
		participants() {
			return this.$store.getters.participantsList(this.token).slice().sort(this.sortParticipants)
		},

		filteredParticipants() {
			const isMatch = (string) => string.toLowerCase().includes(this.searchText.toLowerCase())

			return this.participants.filter(participant => {
				return isMatch(participant.displayName)
					|| (participant.actorType !== 'guests' && isMatch(participant.actorId))
			})
		},

		searchBoxPlaceholder() {
			return this.canAdd
				? t('spreed', 'Search or add participants')
				: t('spreed', 'Search participants')
		},
		show() {
			return this.$store.getters.getSidebarStatus
		},
		opened() {
			return !!this.token && this.show
		},
		token() {
			return this.$store.getters.getToken()
		},
		conversation() {
			return this.$store.getters.conversation(this.token) || this.$store.getters.dummyConversation
		},
		isSearching() {
			return this.searchText !== ''
		},
		noResults() {
			return this.searchResults === []
		},
	},

	beforeMount() {
		EventBus.$on('route-change', this.abortSearch)
		subscribe('user_status:status.updated', this.updateUserStatus)

		// Initialises the get participants mixin
		this.initialiseGetParticipantsMixin()
	},

	beforeDestroy() {
		EventBus.$off('route-change', this.abortSearch)
		unsubscribe('user_status:status.updated', this.updateUserStatus)

		this.cancelSearchPossibleConversations()
		this.cancelSearchPossibleConversations = null

		this.stopGetParticipantsMixin()
	},

	methods: {
		handleClose() {
			this.$store.dispatch('hideSidebar')
		},

		handleInput() {
			this.contactsLoading = true
			this.searchResults = []
			this.debounceFetchSearchResults()
		},

		debounceFetchSearchResults: debounce(function() {
			if (this.isSearching) {
				this.fetchSearchResults()
			}
		}, 250),

		async fetchSearchResults() {
			try {
				this.cancelSearchPossibleConversations('canceled')
				const { request, cancel } = CancelableRequest(searchPossibleConversations)
				this.cancelSearchPossibleConversations = cancel

				const response = await request({
					searchText: this.searchText,
					token: this.token,
				})

				this.searchResults = response?.data?.ocs?.data || []
				this.contactsLoading = false
			} catch (exception) {
				if (CancelableRequest.isCancel(exception)) {
					return
				}
				console.error(exception)
				showError(t('spreed', 'An error occurred while performing the search'))
			}
		},

		/**
		 * Add the selected group/user/circle to the conversation
		 *
		 * @param {object} item The autocomplete suggestion to start a conversation with
		 * @param {string} item.id The ID of the target
		 * @param {string} item.source The source of the target
		 */
		async addParticipants(item) {
			try {
				await addParticipant(this.token, item.id, item.source)
				this.abortSearch()
				this.cancelableGetParticipants()
			} catch (exception) {
				console.debug(exception)
				showError(t('spreed', 'An error occurred while adding the participants'))
			}
		},

		// Ends the search operation
		abortSearch() {
			this.searchText = ''
			if (this.cancelSearchPossibleConversations) {
				this.cancelSearchPossibleConversations()
			}
		},

		updateUserStatus(state) {
			if (!this.token) {
				return
			}

			if (this.participants.find(participant => participant.actorId === state.userId)) {
				this.$store.dispatch('updateUser', {
					token: this.token,
					participantIdentifier: {
						actorType: 'users',
						actorId: state.userId,
					},
					updatedData: {
						status: state.status,
						statusIcon: state.icon,
						statusMessage: state.message,
					},
				})
			}
		},
	},
}
</script>

<style scoped>
.wrapper {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.scroller {
	overflow-y: auto;
}

/** TODO: fix these in the nextcloud-vue library **/

:deep(.app-sidebar-header__menu) {
	top: 6px !important;
	margin-top: 0 !important;
	right: 54px !important;
}

:deep(.app-sidebar__close) {
	top: 6px !important;
	right: 6px !important;
}

/*
 * The field will fully overlap the top of the sidebar content so
 * that elements will scroll behind it
 */
.app-navigation-search {
	top: -10px;
	margin: -10px;
	padding: 10px;
}

</style>
