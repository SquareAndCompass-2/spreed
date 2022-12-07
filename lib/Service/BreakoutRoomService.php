<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2022 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Service;

use InvalidArgumentException;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Config;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\BreakoutRoom;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Webinary;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Notification\IManager as INotificationManager;
use OCP\IL10N;

class BreakoutRoomService {
	protected Config $config;
	protected Manager $manager;
	protected RoomService $roomService;
	protected ParticipantService $participantService;
	protected ChatManager $chatManager;
	protected INotificationManager $notificationManager;
	protected IEventDispatcher $dispatcher;
	protected IL10N $l;

	public function __construct(Config $config,
								Manager $manager,
								RoomService $roomService,
								ParticipantService $participantService,
								ChatManager $chatManager,
								INotificationManager $notificationManager,
								IEventDispatcher $dispatcher,
								IL10N $l) {
		$this->config = $config;
		$this->manager = $manager;
		$this->roomService = $roomService;
		$this->participantService = $participantService;
		$this->chatManager = $chatManager;
		$this->notificationManager = $notificationManager;
		$this->dispatcher = $dispatcher;
		$this->l = $l;
	}

	/**
	 * @param Room $parent
	 * @param int $mode
	 * @psalm-param 0|1|2|3 $mode
	 * @param int $amount
	 * @param string $attendeeMap
	 * @return Room[]
	 * @throws InvalidArgumentException When the breakout rooms are configured already
	 */
	public function setupBreakoutRooms(Room $parent, int $mode, int $amount, string $attendeeMap): array {
		if (!$this->config->isBreakoutRoomsEnabled()) {
			throw new InvalidArgumentException('config');
		}

		if ($parent->getBreakoutRoomMode() !== BreakoutRoom::MODE_NOT_CONFIGURED) {
			throw new InvalidArgumentException('room');
		}

		if ($parent->getType() !== Room::TYPE_GROUP
			&& $parent->getType() !== Room::TYPE_PUBLIC) {
			// Can only do breakout rooms in group and public rooms
			throw new InvalidArgumentException('room');
		}

		if ($parent->getObjectType() === BreakoutRoom::PARENT_OBJECT_TYPE) {
			// Can not nest breakout rooms
			throw new InvalidArgumentException('room');
		}

		if (!$this->roomService->setBreakoutRoomMode($parent, $mode)) {
			throw new InvalidArgumentException('mode');
		}

		if ($amount < BreakoutRoom::MINIMUM_ROOM_AMOUNT) {
			throw new InvalidArgumentException('amount');
		}

		if ($amount > BreakoutRoom::MAXIMUM_ROOM_AMOUNT) {
			throw new InvalidArgumentException('amount');
		}

		if ($mode === BreakoutRoom::MODE_MANUAL) {
			try {
				$attendeeMap = json_decode($attendeeMap, true, 2, JSON_THROW_ON_ERROR);
			} catch (\JsonException $e) {
				throw new InvalidArgumentException('attendeeMap');
			}

			if (!empty($attendeeMap)) {
				if (max($attendeeMap) >= $amount) {
					throw new InvalidArgumentException('attendeeMap');
				}

				if (min($attendeeMap) < 0) {
					throw new InvalidArgumentException('attendeeMap');
				}
			}
		}

		$breakoutRooms = $this->createBreakoutRooms($parent, $amount);

		$participants = $this->participantService->getParticipantsForRoom($parent);
		// TODO Removing any non-users here as breakout rooms only support logged in users in version 1
		$participants = array_filter($participants, static fn (Participant $participant) => $participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS);

		$moderators = array_filter($participants, static fn (Participant $participant) => $participant->hasModeratorPermissions());
		$this->addModeratorsToBreakoutRooms($breakoutRooms, $moderators);

		$others = array_filter($participants, static fn (Participant $participant) => !$participant->hasModeratorPermissions());
		if ($mode === BreakoutRoom::MODE_AUTOMATIC) {
			// Shuffle the attendees, so they are not always distributed in the same way
			shuffle($others);

			$map = [];
			foreach ($others as $index => $participant) {
				$map[$index % $amount] ??= [];
				$map[$index % $amount][] = $participant;
			}

			$this->addOthersToBreakoutRooms($breakoutRooms, $map);
		} elseif ($mode === BreakoutRoom::MODE_MANUAL) {
			$map = [];
			foreach ($others as $participant) {
				$roomNumber = $attendeeMap[$participant->getAttendee()->getId()] ?? null;
				if ($roomNumber === null) {
					continue;
				}

				$roomNumber = (int) $roomNumber;

				$map[$roomNumber] ??= [];
				$map[$roomNumber][] = $participant;
			}

			$this->addOthersToBreakoutRooms($breakoutRooms, $map);
		}


		return $breakoutRooms;
	}

	/**
	 * @param Room[] $rooms
	 * @param Participant[] $moderators
	 */
	protected function addModeratorsToBreakoutRooms(array $rooms, array $moderators): void {
		$moderatorsToAdd = [];
		foreach ($moderators as $moderator) {
			$attendee = $moderator->getAttendee();

			$moderatorsToAdd[] = [
				'actorType' => $attendee->getActorType(),
				'actorId' => $attendee->getActorId(),
				'displayName' => $attendee->getDisplayName(),
				'participantType' => $attendee->getParticipantType(),
			];
		}

		foreach ($rooms as $room) {
			$this->participantService->addUsers($room, $moderatorsToAdd);
		}
	}

	/**
	 * @param array $rooms
	 * @param Participant[][] $participantsMap
	 */
	protected function addOthersToBreakoutRooms(array $rooms, array $participantsMap): void {
		foreach ($rooms as $roomNumber => $room) {
			$toAdd = [];

			$participants = $participantsMap[$roomNumber] ?? [];
			foreach ($participants as $participant) {
				$attendee = $participant->getAttendee();

				$toAdd[] = [
					'actorType' => $attendee->getActorType(),
					'actorId' => $attendee->getActorId(),
					'displayName' => $attendee->getDisplayName(),
					'participantType' => $attendee->getParticipantType(),
				];
			}

			if (empty($toAdd)) {
				continue;
			}

			$this->participantService->addUsers($room, $toAdd);
		}
	}

	protected function createBreakoutRooms(Room $parent, int $amount): array {
		// Safety caution cleaning up potential orphan rooms
		$this->deleteBreakoutRooms($parent);

		// TRANSLATORS Label for the breakout rooms, this is not a plural! The result will be "Room 1", "Room 2", "Room 3", ...
		$label = $this->l->t('Room {number}');

		$rooms = [];
		for ($i = 1; $i <= $amount; $i++) {
			$breakoutRoom = $this->roomService->createConversation(
				$parent->getType(),
				str_replace('{number}', (string) $i, $label),
				null,
				BreakoutRoom::PARENT_OBJECT_TYPE,
				$parent->getToken()
			);

			$this->roomService->setLobby($breakoutRoom, Webinary::LOBBY_NON_MODERATORS, null, false, false);

			$rooms[] = $breakoutRoom;
		}

		return $rooms;
	}

	public function removeBreakoutRooms(Room $parent): void {
		$this->deleteBreakoutRooms($parent);
		$this->roomService->setBreakoutRoomMode($parent, BreakoutRoom::MODE_NOT_CONFIGURED);
	}

	protected function deleteBreakoutRooms(Room $parent): void {
		$breakoutRooms = $this->manager->getMultipleRoomsByObject(BreakoutRoom::PARENT_OBJECT_TYPE, $parent->getToken());
		foreach ($breakoutRooms as $breakoutRoom) {
			$this->roomService->deleteRoom($breakoutRoom);
		}
	}

	public function broadcastChatMessage(Room $parent, Participant $participant, string $message): void {
		if ($parent->getBreakoutRoomMode() === BreakoutRoom::MODE_NOT_CONFIGURED) {
			throw new \InvalidArgumentException('mode');
		}

		$breakoutRooms = $this->manager->getMultipleRoomsByObject(BreakoutRoom::PARENT_OBJECT_TYPE, $parent->getToken());
		$attendeeType = $participant->getAttendee()->getActorType();
		$attendeeId = $participant->getAttendee()->getActorId();
		$creationDateTime = new \DateTime();

		$shouldFlush = $this->notificationManager->defer();
		try {
			foreach ($breakoutRooms as $breakoutRoom) {
				$breakoutParticipant = $this->participantService->getParticipantByActor($breakoutRoom, $attendeeType, $attendeeId);
				$this->chatManager->sendMessage($breakoutRoom, $breakoutParticipant, $attendeeType, $attendeeId, $message, $creationDateTime, null, '', false);
			}
		} finally {
			if ($shouldFlush) {
				$this->notificationManager->flush();
			}
		}
	}

	public function setBreakoutRoomAssistanceRequest(Room $breakoutRoom, int $status): void {
		if ($breakoutRoom->getObjectType() !== BreakoutRoom::PARENT_OBJECT_TYPE) {
			throw new \InvalidArgumentException('room');
		}

		if ($breakoutRoom->getLobbyState() !== Webinary::LOBBY_NONE) {
			throw new \InvalidArgumentException('room');
		}

		if (!in_array($status, [
			BreakoutRoom::STATUS_ASSISTANCE_RESET,
			BreakoutRoom::STATUS_ASSISTANCE_REQUESTED,
		], true)) {
			throw new \InvalidArgumentException('status');
		}

		$this->roomService->setBreakoutRoomStatus($breakoutRoom, $status);
	}

	public function startBreakoutRooms(Room $parent): void {
		if ($parent->getBreakoutRoomMode() === BreakoutRoom::MODE_NOT_CONFIGURED) {
			throw new \InvalidArgumentException('mode');
		}

		$breakoutRooms = $this->manager->getMultipleRoomsByObject(BreakoutRoom::PARENT_OBJECT_TYPE, $parent->getToken());
		foreach ($breakoutRooms as $breakoutRoom) {
			$this->roomService->setLobby($breakoutRoom, Webinary::LOBBY_NONE, null);
		}

		$this->roomService->setBreakoutRoomStatus($parent, BreakoutRoom::STATUS_STARTED);

		// FIXME missing to send the signaling messages so participants are moved
	}

	public function stopBreakoutRooms(Room $parent): void {
		if ($parent->getBreakoutRoomMode() === BreakoutRoom::MODE_NOT_CONFIGURED) {
			throw new \InvalidArgumentException('mode');
		}

		$breakoutRooms = $this->manager->getMultipleRoomsByObject(BreakoutRoom::PARENT_OBJECT_TYPE, $parent->getToken());
		foreach ($breakoutRooms as $breakoutRoom) {
			$this->roomService->setLobby($breakoutRoom, Webinary::LOBBY_NON_MODERATORS, null);
		}

		$this->roomService->setBreakoutRoomStatus($parent, BreakoutRoom::STATUS_STOPPED);

		// FIXME missing to send the signaling messages so participants are moved back
	}
}
