<?php


namespace OCA\Circles\Events;


use OCA\Circles\Service\CirclesService;
use OCA\Circles\Service\GroupsService;
use OCA\Circles\Service\MembersService;
use OCA\Circles\Service\MiscService;
use OCP\IUser;
use OC\Files\View;
use OCA\Circles\Api\v1\Circles;
use OCP\Activity\IEvent;

class UserEvents {

	/** @var CirclesService */
	private $circlesService;

	/** @var MembersService */
	private $membersService;

	/** @var GroupsService */
	private $groupsService;

	/** @var MiscService */
	private $miscService;
	
	/** @var IUser */
	private static $user;

	/**
	 * UserEvents constructor.
	 *
	 * @param CirclesService $circlesService
	 * @param MembersService $membersService
	 * @param GroupsService $groupsService
	 * @param MiscService $miscService
	 */
	public function __construct(
		CirclesService $circlesService, MembersService $membersService, GroupsService $groupsService,
		MiscService $miscService
	) {
		$this->circlesService = $circlesService;
		$this->membersService = $membersService;
		$this->groupsService = $groupsService;
		$this->miscService = $miscService;
	}


	/**
	 * @param array $params
	 */
	public function onUserDeleted(array $params) {
		$userId = $params['uid'];
		$this->circlesService->onUserRemoved($userId);
		$this->membersService->onUserRemoved($userId);
	}


	/**
	 * @param array $params
	 */
	public function onGroupDeleted(array $params) {
		$groupId = $params['gid'];
		$this->groupsService->onGroupRemoved($groupId);
	}
	
	/**
	 *
	 * @param array $params
	 */
	public function onItemShared(array $params) {
		$shareWith = $params['shareWith'];
		$fileTarget = $params['fileTarget'];
		$user = $this->getUser()->getDisplayName();
		$affectedUsers = [];
		$owner = null;
		try {
			$path = Circles::getViewPath($params['nodeId']);
		} catch (NotFoundException $e) {
			$path = \OC::$server->getURLGenerator()->getBaseUrl() . '/apps/files';
		}
		try {
			$circle = $this->circlesService->infoCircleByName($shareWith, true, true);
			$shareWith = $circle->getName();
			$affectedUsers = function ($circle) {
				foreach ($circle->getMembers() as $member){
					yield $member->getDisplayName();
				}
			};
			$owner = $circle->getOwner();
		} catch ( \Exception $e ) {
			$circle = $this->circlesService->detailsCircle($shareWith, true);
			$shareWith = $circle->getName();
			$affectedUsers = function ($circle) {
				foreach ($circle->getMembers() as $member){
					yield $member->getDisplayName();
				}
			};
			$owner = $circle->getOwner();
		}
		$this->miscService->log("user $user shared $fileTarget with $shareWith");

		$subjectParams = [
			'author' => [
				'id'   => $this->getUser()->getUID(),
				'name' => $user 
			],	
			'circle' => [
				'name' => $shareWith
			],
			'file' => [
				'id'   => $params['nodeId'],
				'name' => $fileTarget,
				'type' => $params['itemType']
			]
		];
		$objectType = ($params ['id']) ? 'files' : '';
		$link = \OC::$server->getURLGenerator ()->linkToRouteAbsolute('files.view.index', array (
				'dir' => ($params['itemType'] !== 'file') ? dirname($path) : $path 
		) );
		$event = \OC::$server->getActivityManager()->generateEvent('shared');
		$event->setApp('files_sharing')
			->setType('shared')
			->setAffectedUser($this->getUser()->getDisplayName())
			->setTimestamp(time())
			->setSubject('shared_circle_self', $subjectParams)
			->setParsedSubject("$user shared $fileTarget with the circle $shareWith")
			->setObject($objectType,(int) $params ['id'], $fileTarget )
			->setLink($link);
		if (!is_array($affectedUsers)){
			$affectedUsers = [$this->getUser()->getDisplayName()];
		}
		if ($owner !== null && !in_array($owner->getDisplayName(), $affectedUsers)) {
			$affectedUsers[] = $owner->getDisplayName();
		}
		if (!in_array('admin', $affectedUsers)) {
			$affectedUsers[] = 'admin';
		}
		$this->publishEvent($event, $affectedUsers);
	}
	
	/**
	 *
	 * @param array $params
	 */
	public function onItemUnshared(array $params) {
		$shareWith = $params ['shareWith'];
		$fileTarget = $params ['fileTarget'];
		$user = $this->getUser()->getDisplayName ();
		$affectedUsers = [];
		$owner = null;
		try {
			$circle = $this->circlesService->infoCircleByName($shareWith, true, true);
			$shareWith = $circle->getName();
			$affectedUsers = function ($circle) {
				foreach ($circle->getMembers() as $member){
					yield $member->getDisplayName();
				}
			};
			$owner = $circle->getOwner();
		} catch ( \Exception $e ) {
			try {
				$circle = $this->circlesService->detailsCircle($shareWith, true);
				$shareWith = $circle->getName();
				$affectedUsers = function ($circle) {
					foreach ($circle->getMembers() as $member){
						yield $member->getDisplayName();
					}
				};
				$owner = $circle->getOwner();
			} catch (\Exception $e) {
				$circle = $this->circlesService->infoCircleByUniqueId($shareWith, true);
				$shareWith = $circle->getName();
				$affectedUsers = function ($circle) {
					foreach ($circle->getMembers() as $member){
						yield $member->getDisplayName();
					}
				};
				$owner = $circle->getOwner();
			}
		}
		$this->miscService->log ( "user $user unshared $fileTarget with $shareWith");
		try {
			$path = Circles::getViewPath($params['nodeId']);
		} catch ( NotFoundException $e ) {
			$path = \OC::$server->getURLGenerator()->getBaseUrl() . '/apps/files';
		}
		$subjectParams = [
			'author' => [
				'id'   => $this->getUser()->getUID(),
				'name' => $user 
			],	
			'circle' => [
				'name' => $shareWith
			],
			'file' => [
				'id'   => $params['nodeId'],
				'name' => $fileTarget,
				'type' => $params['itemType']
			]
		];
		$objectType = ($params ['id']) ? 'files' : '';
		$link = \OC::$server->getURLGenerator ()->linkToRouteAbsolute ( 'files.view.index', array (
				'dir' => ($params ['itemType'] !== 'file') ? dirname ( $path ) : $path 
		) );
		$event = \OC::$server->getActivityManager ()->generateEvent ('shared');
		$event->setApp('files_sharing')
			->setType('shared')
			->setTimestamp(time())
			->setSubject('unshared_circle_self', $subjectParams )
			->setParsedSubject("$user unshared $fileTarget with the circle $shareWith")
			->setObject($objectType,(int)$params ['id'], $params ['fileTarget'])
			->setLink ( $link );
		if (!is_array($affectedUsers)){
			$affectedUsers = [$this->getUser()->getDisplayName()];
		}
		if ($owner !== null && !in_array($owner->getDisplayName(), $affectedUsers)) {
			$affectedUsers[] = $owner->getDisplayName();
		}
		if (!in_array('admin', $affectedUsers)) {
			$affectedUsers[] = 'admin';
		}
		$this->publishEvent($event, $affectedUsers);
	}
	
	/**
	 *
	 * @return User
	 */
	private function getUser() {
		if (self::$user == null) {
			self::$user = \OC::$server->getUserSession()->getUser();
		}
		return self::$user;
	}
	
	/**
	 * @param IEvent $event
	 * @param array $affectedUsers
	 */
	private function publishEvent(IEvent $event, array $affectedUsers) {
		foreach($affectedUsers as $affectedUser) {
			$event->setAffectedUser($affectedUser);
			\OC::$server->getActivityManager()->publish($event);
		}
	}
}