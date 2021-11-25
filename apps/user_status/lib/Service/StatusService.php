<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020, Georg Ehrke
 *
 * @author Georg Ehrke <oc.list@georgehrke.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\UserStatus\Service;

use OCA\UserStatus\Db\UserStatus;
use OCA\UserStatus\Db\UserStatusMapper;
use OCA\UserStatus\Exception\InvalidClearAtException;
use OCA\UserStatus\Exception\InvalidMessageIdException;
use OCA\UserStatus\Exception\InvalidStatusIconException;
use OCA\UserStatus\Exception\InvalidStatusTypeException;
use OCA\UserStatus\Exception\StatusMessageTooLongException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\UserStatus\IUserStatus;

/**
 * Class StatusService
 *
 * @package OCA\UserStatus\Service
 */
class StatusService {

	/** @var UserStatusMapper */
	private $mapper;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var PredefinedStatusService */
	private $predefinedStatusService;

	/** @var EmojiService */
	private $emojiService;

	/** @var bool */
	private $shareeEnumeration;

	/** @var bool */
	private $shareeEnumerationInGroupOnly;

	/** @var bool */
	private $shareeEnumerationPhone;

	/**
	 * List of priorities ordered by their priority
	 */
	public const PRIORITY_ORDERED_STATUSES = [
		IUserStatus::ONLINE,
		IUserStatus::AWAY,
		IUserStatus::DND,
		IUserStatus::INVISIBLE,
		IUserStatus::OFFLINE,
	];

	/**
	 * List of statuses that persist the clear-up
	 * or UserLiveStatusEvents
	 */
	public const PERSISTENT_STATUSES = [
		IUserStatus::AWAY,
		IUserStatus::DND,
		IUserStatus::INVISIBLE,
	];

	/** @var int */
	public const INVALIDATE_STATUS_THRESHOLD = 15 /* minutes */ * 60 /* seconds */;

	/** @var int */
	public const MAXIMUM_MESSAGE_LENGTH = 80;

	/**
	 * StatusService constructor.
	 *
	 * @param UserStatusMapper $mapper
	 * @param ITimeFactory $timeFactory
	 * @param PredefinedStatusService $defaultStatusService
	 * @param EmojiService $emojiService
	 * @param IConfig $config
	 */
	public function __construct(UserStatusMapper $mapper,
								ITimeFactory $timeFactory,
								PredefinedStatusService $defaultStatusService,
								EmojiService $emojiService,
								IConfig $config) {
		$this->mapper = $mapper;
		$this->timeFactory = $timeFactory;
		$this->predefinedStatusService = $defaultStatusService;
		$this->emojiService = $emojiService;
		$this->shareeEnumeration = $config->getAppValue('core', 'shareapi_allow_share_dialog_user_enumeration', 'yes') === 'yes';
		$this->shareeEnumerationInGroupOnly = $this->shareeEnumeration && $config->getAppValue('core', 'shareapi_restrict_user_enumeration_to_group', 'no') === 'yes';
		$this->shareeEnumerationPhone = $this->shareeEnumeration && $config->getAppValue('core', 'shareapi_restrict_user_enumeration_to_phone', 'no') === 'yes';
	}

	/**
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return UserStatus[]
	 */
	public function findAll(?int $limit = null, ?int $offset = null): array {
		// Return empty array if user enumeration is disabled or limited to groups
		// TODO: find a solution that scales to get only users from common groups if user enumeration is limited to
		//       groups. See discussion at https://github.com/nextcloud/server/pull/27879#discussion_r729715936
		if (!$this->shareeEnumeration || $this->shareeEnumerationInGroupOnly || $this->shareeEnumerationPhone) {
			return [];
		}

		return array_map(function ($status) {
			return $this->processStatus($status);
		}, $this->mapper->findAll($limit, $offset));
	}

	/**
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return array
	 */
	public function findAllRecentStatusChanges(?int $limit = null, ?int $offset = null): array {
		// Return empty array if user enumeration is disabled or limited to groups
		// TODO: find a solution that scales to get only users from common groups if user enumeration is limited to
		//       groups. See discussion at https://github.com/nextcloud/server/pull/27879#discussion_r729715936
		if (!$this->shareeEnumeration || $this->shareeEnumerationInGroupOnly || $this->shareeEnumerationPhone) {
			return [];
		}

		return array_map(function ($status) {
			return $this->processStatus($status);
		}, $this->mapper->findAllRecent($limit, $offset));
	}

	/**
	 * @param string $userId
	 * @return UserStatus
	 * @throws DoesNotExistException
	 */
	public function findByUserId(string $userId):UserStatus {
		return $this->processStatus($this->mapper->findByUserId($userId));
	}

	/**
	 * @param array $userIds
	 * @return UserStatus[]
	 */
	public function findByUserIds(array $userIds):array {
		return array_map(function ($status) {
			return $this->processStatus($status);
		}, $this->mapper->findByUserIds($userIds));
	}

	/**
	 * @param string $userId
	 * @param string $status
	 * @param int|null $statusTimestamp
	 * @param bool $isUserDefined
	 * @return UserStatus
	 * @throws InvalidStatusTypeException
	 */
	public function setStatus(string $userId,
							  string $status,
							  ?int $statusTimestamp,
							  bool $isUserDefined): UserStatus {
		try {
			$userStatus = $this->mapper->findByUserId($userId);
		} catch (DoesNotExistException $ex) {
			$userStatus = new UserStatus();
			$userStatus->setUserId($userId);
		}

		// Check if status-type is valid
		if (!\in_array($status, self::PRIORITY_ORDERED_STATUSES, true)) {
			throw new InvalidStatusTypeException('Status-type "' . $status . '" is not supported');
		}
		if ($statusTimestamp === null) {
			$statusTimestamp = $this->timeFactory->getTime();
		}

		$userStatus->setStatus($status);
		$userStatus->setStatusTimestamp($statusTimestamp);
		$userStatus->setIsUserDefined($isUserDefined);
		$userStatus->setIsBackup(false);

		if ($userStatus->getId() === null) {
			return $this->mapper->insert($userStatus);
		}

		return $this->mapper->update($userStatus);
	}

	/**
	 * @param string $userId
	 * @param string $messageId
	 * @param int|null $clearAt
	 * @return UserStatus
	 * @throws InvalidMessageIdException
	 * @throws InvalidClearAtException
	 */
	public function setPredefinedMessage(string $userId,
										 string $messageId,
										 ?int $clearAt): UserStatus {
		try {
			$userStatus = $this->mapper->findByUserId($userId);
		} catch (DoesNotExistException $ex) {
			$userStatus = new UserStatus();
			$userStatus->setUserId($userId);
			$userStatus->setStatus(IUserStatus::OFFLINE);
			$userStatus->setStatusTimestamp(0);
			$userStatus->setIsUserDefined(false);
		}

		if (!$this->predefinedStatusService->isValidId($messageId)) {
			throw new InvalidMessageIdException('Message-Id "' . $messageId . '" is not supported');
		}

		// Check that clearAt is in the future
		if ($clearAt !== null && $clearAt < $this->timeFactory->getTime()) {
			throw new InvalidClearAtException('ClearAt is in the past');
		}

		$userStatus->setMessageId($messageId);
		$userStatus->setCustomIcon(null);
		$userStatus->setCustomMessage(null);
		$userStatus->setClearAt($clearAt);

		if ($userStatus->getId() === null) {
			return $this->mapper->insert($userStatus);
		}

		return $this->mapper->update($userStatus);
	}

	/**
	 * @param string $userId
	 * @param string|null $statusIcon
	 * @param string $message
	 * @param int|null $clearAt
	 * @return UserStatus
	 * @throws InvalidClearAtException
	 * @throws InvalidStatusIconException
	 * @throws StatusMessageTooLongException
	 */
	public function setCustomMessage(string $userId,
									 ?string $statusIcon,
									 string $message,
									 ?int $clearAt): UserStatus {
		try {
			$userStatus = $this->mapper->findByUserId($userId);
		} catch (DoesNotExistException $ex) {
			$userStatus = new UserStatus();
			$userStatus->setUserId($userId);
			$userStatus->setStatus(IUserStatus::OFFLINE);
			$userStatus->setStatusTimestamp(0);
			$userStatus->setIsUserDefined(false);
		}

		// Check if statusIcon contains only one character
		if ($statusIcon !== null && !$this->emojiService->isValidEmoji($statusIcon)) {
			throw new InvalidStatusIconException('Status-Icon is longer than one character');
		}
		// Check for maximum length of custom message
		if (\mb_strlen($message) > self::MAXIMUM_MESSAGE_LENGTH) {
			throw new StatusMessageTooLongException('Message is longer than supported length of ' . self::MAXIMUM_MESSAGE_LENGTH . ' characters');
		}
		// Check that clearAt is in the future
		if ($clearAt !== null && $clearAt < $this->timeFactory->getTime()) {
			throw new InvalidClearAtException('ClearAt is in the past');
		}

		$userStatus->setMessageId(null);
		$userStatus->setCustomIcon($statusIcon);
		$userStatus->setCustomMessage($message);
		$userStatus->setClearAt($clearAt);

		if ($userStatus->getId() === null) {
			return $this->mapper->insert($userStatus);
		}

		return $this->mapper->update($userStatus);
	}

	/**
	 * @param string $userId
	 * @return bool
	 */
	public function clearStatus(string $userId): bool {
		try {
			$userStatus = $this->mapper->findByUserId($userId);
		} catch (DoesNotExistException $ex) {
			// if there is no status to remove, just return
			return false;
		}

		$userStatus->setStatus(IUserStatus::OFFLINE);
		$userStatus->setStatusTimestamp(0);
		$userStatus->setIsUserDefined(false);

		$this->mapper->update($userStatus);
		return true;
	}

	/**
	 * @param string $userId
	 * @return bool
	 */
	public function clearMessage(string $userId): bool {
		try {
			$userStatus = $this->mapper->findByUserId($userId);
		} catch (DoesNotExistException $ex) {
			// if there is no status to remove, just return
			return false;
		}

		$userStatus->setMessageId(null);
		$userStatus->setCustomMessage(null);
		$userStatus->setCustomIcon(null);
		$userStatus->setClearAt(null);

		$this->mapper->update($userStatus);
		return true;
	}

	/**
	 * @param string $userId
	 * @return bool
	 */
	public function removeUserStatus(string $userId, bool $isBackup = false): bool {
		try {
			$userStatus = $this->mapper->findByUserId($userId, $isBackup);
		} catch (DoesNotExistException $ex) {
			// if there is no status to remove, just return
			return false;
		}

		$this->mapper->delete($userStatus);
		return true;
	}

	/**
	 * Processes a status to check if custom message is still
	 * up to date and provides translated default status if needed
	 *
	 * @param UserStatus $status
	 * @return UserStatus
	 */
	private function processStatus(UserStatus $status): UserStatus {
		$clearAt = $status->getClearAt();

		if ($status->getStatusTimestamp() < $this->timeFactory->getTime() - self::INVALIDATE_STATUS_THRESHOLD
			&& (!$status->getIsUserDefined() || $status->getStatus() === IUserStatus::ONLINE)) {
			$this->cleanStatus($status);
		}
		if ($clearAt !== null && $clearAt < $this->timeFactory->getTime()) {
			$this->cleanStatusMessage($status);
		}
		if ($status->getMessageId() !== null) {
			$this->addDefaultMessage($status);
		}

		return $status;
	}

	/**
	 * @param UserStatus $status
	 */
	private function cleanStatus(UserStatus $status): void {
		if ($status->getStatus() === IUserStatus::OFFLINE && !$status->getIsUserDefined()) {
			return;
		}

		$status->setStatus(IUserStatus::OFFLINE);
		$status->setStatusTimestamp($this->timeFactory->getTime());
		$status->setIsUserDefined(false);

		$this->mapper->update($status);
	}

	/**
	 * @param UserStatus $status
	 */
	private function cleanStatusMessage(UserStatus $status): void {
		$status->setMessageId(null);
		$status->setCustomIcon(null);
		$status->setCustomMessage(null);
		$status->setClearAt(null);

		$this->mapper->update($status);
	}

	/**
	 * @param UserStatus $status
	 */
	private function addDefaultMessage(UserStatus $status): void {
		// If the message is predefined, insert the translated message and icon
		$predefinedMessage = $this->predefinedStatusService->getDefaultStatusById($status->getMessageId());
		if ($predefinedMessage !== null) {
			$status->setCustomMessage($predefinedMessage['message']);
			$status->setCustomIcon($predefinedMessage['icon']);
		}
	}

	/**
	 * @return bool false iff there is already a backup. In this case abort the procedure.
	 */
	public function backupCurrentStatus(string $userId): bool {
		try {
			$this->mapper->findByUserId($userId, true);
			return false;
		} catch (DoesNotExistException $ex) {
			// No backup already existing => Good
		}

		try {
			$userStatus = $this->mapper->findByUserId($userId);
		} catch (DoesNotExistException $ex) {
			// if there is no status to backup, just return
			return true;
		}

		$userStatus->setIsBackup(true);
		// Prefix user account with an underscore because user_id is marked as unique
		// in the table. Starting an username with an underscore is not allowed so this
		// shouldn't create any trouble.
		$userStatus->setUserId('_' . $userStatus->getUserId());
		$this->mapper->update($userStatus);
		return true;
	}

	public function revertUserStatus(string $userId, string $messageId, string $status): void {
		try {
			/** @var UserStatus $userStatus */
			$backupUserStatus = $this->mapper->findByUserId($userId, true);
		} catch (DoesNotExistException $ex) {
			// No backup, just move back to available
			try {
				$userStatus = $this->mapper->findByUserId($userId);
			} catch (DoesNotExistException $ex) {
				// No backup nor current status => ignore
				return;
			}
			$this->cleanStatus($userStatus);
			$this->cleanStatusMessage($userStatus);
			return;
		}
		try {
			$userStatus = $this->mapper->findByUserId($userId);
			if ($userStatus->getMessageId() !== $messageId || $userStatus->getStatus() !== $status) {
				// Another status is set automatically, do nothing
				return;
			}
			$this->removeUserStatus($userId);
		} catch (DoesNotExistException $ex) {
			// No current status => nothing to delete
		}
		$backupUserStatus->setIsBackup(false);
		// Remove the underscore prefix added when creating the backup
		$backupUserStatus->setUserId(substr($backupUserStatus->getUserId(), 1));
		$this->mapper->update($backupUserStatus);
	}
}
