<?php
/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright 2017
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

namespace OCA\Circles\Service;


use Exception;
use OC\Http\Client\ClientService;
use OCA\Circles\Api\v1\Circles;
use OCA\Circles\AppInfo\Application;
use OCA\Circles\Db\CirclesRequest;
use OCA\Circles\Db\FederatedLinksRequest;
use OCA\Circles\Exceptions\CircleDoesNotExistException;
use OCA\Circles\Exceptions\MemberDoesNotExistException;
use OCA\Circles\Exceptions\PayloadDeliveryException;
use OCA\Circles\Exceptions\SharingFrameAlreadyDeliveredException;
use OCA\Circles\Exceptions\SharingFrameAlreadyExistException;
use OCA\Circles\Exceptions\SharingFrameDoesNotExistException;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\FederatedLink;
use OCA\Circles\Model\SharingFrame;
use OCP\Http\Client\IClientService;


class SharingFrameService {

	/** @var string */
	private $userId;

	/** @var ConfigService */
	private $configService;

	/** @var CirclesRequest */
	private $circlesRequest;

	/** @var FederatedLinksRequest */
	private $federatedLinksRequest;

	/** @var BroadcastService */
	private $broadcastService;

	/** @var FederatedLinkService */
	private $federatedLinkService;

	/** @var ClientService */
	private $clientService;

	/** @var MiscService */
	private $miscService;


	/**
	 * SharingFrameService constructor.
	 *
	 * @param string $UserId
	 * @param ConfigService $configService
	 * @param CirclesRequest $circlesRequest
	 * @param FederatedLinksRequest $federatedLinksRequest
	 * @param BroadcastService $broadcastService
	 * @param FederatedLinkService $federatedLinkService
	 * @param IClientService $clientService
	 * @param MiscService $miscService
	 */
	public function __construct(
		$UserId,
		ConfigService $configService,
		CirclesRequest $circlesRequest,
		FederatedLinksRequest $federatedLinksRequest,
		BroadcastService $broadcastService,
		FederatedLinkService $federatedLinkService,
		IClientService $clientService,
		MiscService $miscService
	) {
		$this->userId = $UserId;
		$this->configService = $configService;
		$this->circlesRequest = $circlesRequest;
		$this->federatedLinksRequest = $federatedLinksRequest;
		$this->broadcastService = $broadcastService;
		$this->federatedLinkService = $federatedLinkService;
		$this->clientService = $clientService;
		$this->miscService = $miscService;
	}


	/**
	 * createFrame()
	 *
	 * Save the Frame containing the Payload.
	 * The Payload will be shared locally, and spread it live if a Broadcaster is set.
	 * Function will also initiate the federated broadcast to linked circles.
	 *
	 * @param string $circleUniqueId
	 * @param SharingFrame $frame
	 * @param string|null $broadcast
	 *
	 * @throws Exception
	 * @throws MemberDoesNotExistException
	 */
	public function createFrame($circleUniqueId, SharingFrame $frame, $broadcast = null) {

		try {
			$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
			$circle->getHigherViewer()
				   ->hasToBeMember();

			$frame->setCircle($circle);

			$this->generateHeaders($frame, $circle, $broadcast);
			$this->circlesRequest->saveFrame($frame);

			$this->initiateShare($circle->getUniqueId(), $frame->getUniqueId());
		} catch (Exception $e) {
			throw $e;
		}
	}


	/**
	 * Generate Headers and few more thing like UniqueId and Author.
	 * Check if the source is NOT Circles.
	 *
	 * @param SharingFrame $frame
	 * @param Circle $circle
	 * @param $broadcast
	 */
	private function generateHeaders(SharingFrame $frame, Circle $circle, $broadcast) {

		try {
			$frame->cannotBeFromCircles();

			$frame->setAuthor($this->userId);
			$frame->setHeader('author', $this->userId);
			$frame->setHeader('circleName', $circle->getName());
			$frame->setHeader('circleUniqueId', $circle->getUniqueId());
			$frame->setHeader('broadcast', (string)$broadcast);
			$frame->generateUniqueId();

		} catch (Exception $e) {
			throw new $e;
		}
	}

	/**
	 * @param string $circleUniqueId
	 * @param string $frameUniqueId
	 *
	 * @return null|SharingFrame
	 * @throws SharingFrameAlreadyDeliveredException
	 * @throws SharingFrameDoesNotExistException
	 */
	public function getFrameFromUniqueId($circleUniqueId, $frameUniqueId) {
		if ($frameUniqueId === null || $frameUniqueId === '') {
			throw new SharingFrameDoesNotExistException('unknown_share');
		}

		try {
			$frame = $this->circlesRequest->getFrame($circleUniqueId, $frameUniqueId);
			if ($frame->getCloudId() !== null) {
				throw new SharingFrameAlreadyDeliveredException('share_already_delivered');
			}
		} catch (SharingFrameDoesNotExistException $e) {
			throw new SharingFrameDoesNotExistException('unknown_share');
		}

		return $frame;
	}


	/**
	 * @param string $token
	 * @param string $uniqueId
	 * @param SharingFrame $frame
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function receiveFrame($token, $uniqueId, SharingFrame &$frame) {
		try {
			$link = $this->federatedLinksRequest->getLinkFromToken((string)$token, (string)$uniqueId);
		} catch (Exception $e) {
			throw $e;
		}

		try {
			$this->circlesRequest->getFrame($link->getCircleId(), $frame->getUniqueId());
			throw new SharingFrameAlreadyExistException('shares_is_already_known');
		} catch (SharingFrameDoesNotExistException $e) {
		}

		try {
			$circle = $this->circlesRequest->forceGetCircle($link->getCircleId());
		} catch (CircleDoesNotExistException $e) {
			throw new CircleDoesNotExistException('unknown_circle');
		}

		$frame->setCircle($circle);
		$this->circlesRequest->saveFrame($frame);

		return true;
	}


	/**
	 * @param string $circleUniqueId
	 * @param string $frameUniqueId
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function initiateShare($circleUniqueId, $frameUniqueId) {
		$args = [
			'circleId' => $circleUniqueId,
			'frameId'  => $frameUniqueId
		];

		$client = $this->clientService->newClient();
		try {
			$client->post(
				$this->generatePayloadDeliveryURL($this->configService->getLocalAddress()), [
																							  'body'            => $args,
																							  'timeout'         => 10,
																							  'connect_timeout' => 10,
																						  ]
			);

			return true;
		} catch (Exception $e) {
			throw $e;
		}
	}


	/**
	 * @param string $remote
	 *
	 * @return string
	 */
	private function generatePayloadDeliveryURL($remote) {
		return $this->configService->generateRemoteHost($remote) . Application::REMOTE_URL_PAYLOAD;
	}


	/**
	 * @param SharingFrame $frame
	 *
	 * @throws Exception
	 */
	public function forwardSharingFrame(SharingFrame $frame) {

		try {
			$circle = $this->circlesRequest->forceGetCircle(
				$frame->getCircle()
					  ->getUniqueId()
			);
		} catch (CircleDoesNotExistException $e) {
			throw new CircleDoesNotExistException('unknown_circle');
		}

		$links = $this->federatedLinksRequest->getLinksFromCircle(
			$frame->getCircle()
				  ->getUniqueId(), FederatedLink::STATUS_LINK_UP
		);

		$this->forwardSharingFrameToFederatedLinks($circle, $frame, $links);
	}


	/**
	 * @param Circle $circle
	 * @param SharingFrame $frame
	 * @param FederatedLink[] $links
	 */
	private function forwardSharingFrameToFederatedLinks(Circle $circle, SharingFrame $frame, $links) {

		$args = [
			'apiVersion' => Circles::version(),
			'uniqueId'   => $circle->getUniqueId(true),
			'item'       => json_encode($frame)
		];

		foreach ($links AS $link) {
			$args['token'] = $link->getToken(true);
			$this->deliverSharingFrameToLink($link, $args);
		}
	}


	/**
	 * sendRemoteShareToLinks();
	 *
	 * @param FederatedLink $link
	 * @param array $args
	 */
	private function deliverSharingFrameToLink($link, $args) {

		$client = $this->clientService->newClient();
		try {
			$request = $client->put(
				$this->generatePayloadDeliveryURL($link->getAddress()), [
																		  'body'            => $args,
																		  'timeout'         => 10,
																		  'connect_timeout' => 10,
																	  ]
			);

			$result = json_decode($request->getBody(), true);
			if ($result['status'] === -1) {
				throw new PayloadDeliveryException($result['reason']);
			}

		} catch (Exception $e) {
			$this->miscService->log(
				'fail to send frame to ' . $link->getAddress() . ' - ' . $e->getMessage()
			);
		}
	}


	/**
	 * @param SharingFrame $frame
	 */
	public function updateFrameWithCloudId(SharingFrame $frame) {
		$frame->setCloudId($this->configService->getLocalAddress());
		$this->circlesRequest->updateFrame($frame);
	}


}