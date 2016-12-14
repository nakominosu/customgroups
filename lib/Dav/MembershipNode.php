<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\CustomGroups\Dav;

use OCA\CustomGroups\CustomGroupsDatabaseHandler;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\PreconditionFailed;

/**
 * Membership node
 */
class MembershipNode implements \Sabre\DAV\INode, \Sabre\DAV\IProperties {
	const NS_OWNCLOUD = 'http://owncloud.org/ns';

	const PROPERTY_ROLE = '{http://owncloud.org/ns}role';
	const PROPERTY_USER_ID = '{http://owncloud.org/ns}user-id';
	const PROPERTY_GROUP_URI = '{http://owncloud.org/ns}group-uri';

	/**
	 * Custom groups handler
	 *
	 * @var CustomGroupsDatabaseHandler
	 */
	private $groupsHandler;

	/**
	 * Membership information
	 *
	 * @var array
	 */
	private $memberInfo;

	/**
	 * Membership helper
	 *
	 * @var MembershipHelper
	 */
	private $helper;

	/**
	 * Membership info for the currently logged in user
	 *
	 * @var array
	 */
	private $userMemberInfo;

	/**
	 * Node name
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Constructor
	 *
	 * @param array $memberInfo membership information
	 * @param string $name node name (based on user id or group id)
	 * @param CustomGroupsDatabaseHandler $groupsHandler custom groups handler
	 * @param MembershipHelper $helper membership helper
	 */
	public function __construct(
		array $memberInfo,
		$name,
		CustomGroupsDatabaseHandler $groupsHandler,
		MembershipHelper $helper
	) {
		$this->groupsHandler = $groupsHandler;
		$this->name = $name;
		$this->memberInfo = $memberInfo;
		$this->helper = $helper;
	}

	/**
	 * Removes this member from the group
	 *
	 * @throws Forbidden when no permission to delete
	 * @throws PreconditionFailed when membership did not exist
	 */
	public function delete() {
		$currentUserId = $this->helper->getUserId();
		$groupId = $this->memberInfo['group_id'];
		// admins can remove members
		// and regular members can remove themselves
		if (!$this->helper->isUserAdmin($groupId)
			&& !($currentUserId === $this->memberInfo['user_id'] && $this->helper->isUserMember($groupId))
		) {
			throw new Forbidden("No permission to remove members from group \"$groupId\"");
		}

		// can't remove the last admin
		if ($this->helper->isTheOnlyAdmin($groupId, $this->name)) {
			throw new Forbidden("Cannot remove the last admin from the group \"$groupId\"");
		}

		$userId = $this->memberInfo['user_id'];
		if (!$this->groupsHandler->removeFromGroup(
			$userId,
			$groupId
		)) {
			// possibly the membership was deleted concurrently
			throw new PreconditionFailed("Could not remove member \"$userId\" from group \"$groupId\"");
		};
	}

	/**
	 * Returns the node name
	 *
	 * @return string node name
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * Not supported
	 *
	 * @param string $name The new name
	 * @throws MethodNotAllowed not supported
	 */
	public function setName($name) {
		throw new MethodNotAllowed();
	}

	/**
	 * Returns null
	 *
	 * @return int null
	 */
	public function getLastModified() {
		return null;
	}

	/**
	 * Updates properties on this node.
	 *
	 * This method received a PropPatch object, which contains all the
	 * information about the update.
	 *
	 * To update specific properties, call the 'handle' method on this object.
	 * Read the PropPatch documentation for more information.
	 *
	 * @param PropPatch $propPatch PropPatch query
	 */
	public function propPatch(PropPatch $propPatch) {
		$propPatch->handle(self::PROPERTY_ROLE, [$this, 'updateAdminFlag']);
	}

	/**
	 * Returns a list of properties for this node.
	 *
	 * @param array|null $properties requested properties or null for all
	 * @return array property values
	 */
	public function getProperties($properties) {
		$result = [];
		if ($properties === null || in_array(self::PROPERTY_ROLE, $properties)) {
			$result[self::PROPERTY_ROLE] = $this->memberInfo['role'];
		}
		if ($properties === null || in_array(self::PROPERTY_USER_ID, $properties)) {
			$result[self::PROPERTY_USER_ID] = $this->memberInfo['user_id'];
		}
		if ($properties === null || in_array(self::PROPERTY_GROUP_URI, $properties)) {
			$result[self::PROPERTY_GROUP_URI] = $this->memberInfo['uri'];
		}
		return $result;
	}

	/**
	 * Update the admin flag.
	 * Returns 403 status code if the current user has insufficient permissions
	 * or if the only group admin is trying to remove their own permission.
	 *
	 * @param int $rolePropValue role value
	 * @return boolean|int true or error status code
	 */
	public function updateAdminFlag($rolePropValue) {
		$groupId = $this->memberInfo['group_id'];
		$userId = $this->memberInfo['user_id'];
		// only the group admin can change permissions
		if (!$this->helper->isUserAdmin($groupId)) {
			return 403;
		}

		// can't remove admin rights from the last admin
		if ($rolePropValue !== CustomGroupsDatabaseHandler::ROLE_ADMIN && $this->helper->isTheOnlyAdmin($groupId, $userId)) {
			return 403;
		}

		$result = $this->groupsHandler->setGroupMemberInfo(
			$groupId,
			$userId,
			($rolePropValue === CustomGroupsDatabaseHandler::ROLE_ADMIN)
		);
		$this->groupInfo['role'] = $rolePropValue;

		return $result;
	}

}
