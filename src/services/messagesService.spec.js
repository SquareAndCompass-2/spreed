import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import { CHAT } from '../constants.js'
import {
	fetchMessages,
	getMessageContext,
	lookForNewMessages,
	postNewMessage,
	deleteMessage,
	postRichObjectToConversation,
	updateLastReadMessage,
} from './messagesService.js'

jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(),
	post: jest.fn(),
	delete: jest.fn(),
}))

describe('messagesService', () => {
	afterEach(() => {
		// cleaning up the mess left behind the previous test
		jest.clearAllMocks()
	})

	test('fetchMessages calls the chat API endpoint excluding last known', () => {
		fetchMessages({
			token: 'XXTOKENXX',
			lastKnownMessageId: 1234,
			includeLastKnown: 0,
		}, {
			dummyOption: true,
		})

		expect(axios.get).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/chat/XXTOKENXX'),
			{
				dummyOption: true,
				params: {
					setReadMarker: 0,
					lookIntoFuture: 0,
					lastKnownMessageId: 1234,
					limit: CHAT.FETCH_LIMIT,
					includeLastKnown: 0,
				},
			}
		)
	})

	test('fetchMessages calls the chat API endpoint including last known', () => {
		fetchMessages({
			token: 'XXTOKENXX',
			lastKnownMessageId: 1234,
			includeLastKnown: 1,
		}, {
			dummyOption: true,
		})

		expect(axios.get).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/chat/XXTOKENXX'),
			{
				dummyOption: true,
				params: {
					setReadMarker: 0,
					lookIntoFuture: 0,
					lastKnownMessageId: 1234,
					limit: CHAT.FETCH_LIMIT,
					includeLastKnown: 1,
				},
			}
		)
	})

	test('getMessageContext calls the chat API endpoint within specific messageId', () => {
		getMessageContext({
			token: 'XXTOKENXX',
			messageId: 1234,
		}, {
			dummyOption: true,
		})

		expect(axios.get).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/chat/XXTOKENXX/1234/context'),
			{
				dummyOption: true,
				params: {
					limit: CHAT.FETCH_LIMIT / 2,
				},
			}
		)
	})

	test('lookForNewMessages calls the chat API endpoint excluding last known', () => {
		lookForNewMessages({
			token: 'XXTOKENXX',
			lastKnownMessageId: 1234,
		}, {
			dummyOption: true,
		})

		expect(axios.get).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/chat/XXTOKENXX'),
			{
				dummyOption: true,
				params: {
					setReadMarker: 0,
					lookIntoFuture: 1,
					lastKnownMessageId: 1234,
					limit: CHAT.FETCH_LIMIT,
					includeLastKnown: 0,
					markNotificationsAsRead: 0,
				},
			}
		)
	})

	test('postNewMessage calls the chat API endpoint', () => {
		postNewMessage({
			token: 'XXTOKENXX',
			message: 'hello world!',
			actorDisplayName: 'actor-display-name',
			referenceId: 'reference-id',
			parent: { id: 111 },
		}, {
			dummyOption: true,
		})

		expect(axios.post).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/chat/XXTOKENXX'),
			{
				message: 'hello world!',
				actorDisplayName: 'actor-display-name',
				referenceId: 'reference-id',
				replyTo: 111,
			},
			{
				dummyOption: true,
			}
		)
	})

	test('deleteMessage calls the chat API endpoint', () => {
		deleteMessage({
			token: 'XXTOKENXX',
			id: 1234,
		})

		expect(axios.delete).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/chat/XXTOKENXX/1234'),
		)
	})

	test('postRichObjectToConversation calls the chat API endpoint', () => {
		postRichObjectToConversation('XXTOKENXX', {
			objectType: 'deck',
			objectId: 999,
			metaData: '{"x":1}',
			referenceId: 'reference-id',
		})

		expect(axios.post).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/chat/XXTOKENXX/share'),
			{
				objectType: 'deck',
				objectId: 999,
				metaData: '{"x":1}',
				referenceId: 'reference-id',
			}
		)
	})

	test('postRichObjectToConversation without reference id will generate one', () => {
		postRichObjectToConversation('XXTOKENXX', {
			objectType: 'deck',
			objectId: 999,
			metaData: '{"x":1}',
		})

		expect(axios.post).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/chat/XXTOKENXX/share'),
			{
				objectType: 'deck',
				objectId: 999,
				metaData: '{"x":1}',
				referenceId: expect.stringMatching(/^[a-z0-9]{64}$/),
			}
		)
	})

	test('updateLastReadMessage calls the chat API endpoint', () => {
		updateLastReadMessage('XXTOKENXX', 1234)

		expect(axios.post).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/chat/XXTOKENXX/read'),
			{
				lastReadMessage: 1234,
			}
		)
	})
})
