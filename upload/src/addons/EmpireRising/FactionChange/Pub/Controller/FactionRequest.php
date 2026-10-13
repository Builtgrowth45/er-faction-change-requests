<?php

namespace EmpireRising\FactionChange\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;

/**
 * index.php?faction-requests/             the player's form, current details and history
 * index.php?faction-requests/submit       POST: send a request
 * index.php?faction-requests/{id}/cancel  POST: withdraw your own pending request
 * index.php?faction-requests/queue        approvers: pending requests + recent decisions
 * index.php?faction-requests/{id}/approve POST
 * index.php?faction-requests/{id}/reject  POST
 */
class FactionRequest extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertRegistrationRequired();
	}

	public function actionIndex(ParameterBag $params)
	{
		if ($params->request_id)
		{
			return $this->redirect($this->buildLink('faction-requests'));
		}

		$visitor = \XF::visitor();
		$repo = $this->getFactionRepo();

		$history = $this->finder('EmpireRising\FactionChange:Request')
			->where('user_id', $visitor->user_id)
			->order('request_date', 'DESC')
			->limit(10)
			->fetch();

		$canApprove = $repo->canApprove($visitor);
		$current = $repo->getCurrentValues($visitor);

		$viewParams = [
			'current' => $current,
			'currentFactionLabel' => $current['faction'] !== '' ? $repo->getFactionLabel($current['faction']) : '',
			'factionOptions' => $this->getFactionOptions(),
			'rankOptions' => $this->getRankOptions(),
			'pending' => $repo->getPendingForUser($visitor),
			'cooldownUntil' => $repo->getCooldownEnd($visitor),
			'canRequest' => $visitor->hasPermission('erFaction', 'request'),
			'canApprove' => $canApprove,
			'pendingCount' => $canApprove ? $repo->findPendingForApprover($visitor)->total() : 0,
			'history' => $history,
		];
		return $this->view('EmpireRising\FactionChange:Index', 'er_faction_request_index', $viewParams);
	}

	public function actionSubmit()
	{
		$this->assertPostOnly();

		$visitor = \XF::visitor();
		$repo = $this->getFactionRepo();

		if (!$visitor->hasPermission('erFaction', 'request'))
		{
			return $this->noPermission();
		}
		if ($repo->getPendingForUser($visitor))
		{
			return $this->error('You already have a request waiting. Cancel it first if you want to change it.');
		}
		$cooldownUntil = $repo->getCooldownEnd($visitor);
		if ($cooldownUntil)
		{
			return $this->error('You changed faction recently. You can send a new request after '
				. \XF::language()->dateTime($cooldownUntil) . '.');
		}

		$input = $this->filter([
			'ign' => 'str',
			'faction' => 'str',
			'faction_rank' => 'uint',
			'department' => 'str',
			'note' => 'str',
		]);

		$map = $repo->getFactionMap();
		if (!isset($map[$input['faction']]))
		{
			return $this->error('Please choose a faction.');
		}
		if ($input['ign'] === '')
		{
			return $this->error('Please enter your in-game name.');
		}
		if ($input['faction_rank'] > $repo::MAX_RANK)
		{
			return $this->error('Please choose a rank from R0 to R7.');
		}

		/** @var \EmpireRising\FactionChange\Entity\Request $request */
		$request = $this->em()->create('EmpireRising\FactionChange:Request');
		$request->bulkSet($input);
		$request->user_id = $visitor->user_id;
		$request->request_date = \XF::$time;
		if (!$request->preSave())
		{
			return $this->error($request->getErrors());
		}
		$request->save();

		return $this->redirect(
			$this->buildLink('faction-requests'),
			'Your request has been sent. Staff or your new faction\'s leaders will review it.'
		);
	}

	public function actionCancel(ParameterBag $params)
	{
		$this->assertPostOnly();

		$request = $this->assertRequestExists($params->request_id);
		if ($request->user_id != \XF::visitor()->user_id || !$request->isPending())
		{
			return $this->noPermission();
		}

		$request->status = 'cancelled';
		$request->resolved_user_id = \XF::visitor()->user_id;
		$request->resolved_date = \XF::$time;
		$request->save();

		return $this->redirect($this->buildLink('faction-requests'), 'Request cancelled.');
	}

	public function actionQueue()
	{
		$visitor = \XF::visitor();
		$repo = $this->getFactionRepo();
		if (!$repo->canApprove($visitor))
		{
			return $this->noPermission();
		}

		$rows = [];
		foreach ($repo->findPendingForApprover($visitor)->fetch() as $request)
		{
			$current = $request->User ? $repo->getCurrentValues($request->User) : [];
			$rows[] = [
				'request' => $request,
				'current' => $current,
				'currentFactionLabel' => !empty($current['faction']) ? $repo->getFactionLabel($current['faction']) : '',
				'newRank' => $repo->formatRankValue($request),
			];
		}

		$viewParams = [
			'rows' => $rows,
			'recent' => $repo->findRecentlyResolvedForApprover($visitor)->limit(20)->fetch(),
			'approveAny' => $repo->canApproveAny($visitor),
		];
		return $this->view('EmpireRising\FactionChange:Queue', 'er_faction_request_queue', $viewParams);
	}

	public function actionApprove(ParameterBag $params)
	{
		$this->assertPostOnly();

		$request = $this->assertRequestExists($params->request_id);
		$visitor = \XF::visitor();
		if (!$this->getFactionRepo()->canApprove($visitor, $request))
		{
			return $this->noPermission();
		}

		$this->getResolver($request)->approve($visitor);

		return $this->redirect($this->buildLink('faction-requests/queue'), 'Approved. The member\'s profile and faction group have been updated.');
	}

	public function actionReject(ParameterBag $params)
	{
		$this->assertPostOnly();

		$request = $this->assertRequestExists($params->request_id);
		$visitor = \XF::visitor();
		if (!$this->getFactionRepo()->canApprove($visitor, $request))
		{
			return $this->noPermission();
		}

		$reason = $this->filter('reason', 'str');
		$this->getResolver($request)->reject($visitor, $reason);

		return $this->redirect($this->buildLink('faction-requests/queue'), 'Rejected. The member has been told.');
	}

	protected function getFactionOptions(): array
	{
		$options = [];
		foreach ($this->getFactionRepo()->getFactionMap() as $key => $faction)
		{
			$options[$key] = $faction['label'];
		}
		return $options;
	}

	protected function getRankOptions(): array
	{
		$options = [];
		for ($i = 0; $i <= 7; $i++)
		{
			$options[$i] = 'R' . $i . ($i === 0 ? ' (Trainee)' : '');
		}
		return $options;
	}

	/**
	 * @return \EmpireRising\FactionChange\Entity\Request
	 */
	protected function assertRequestExists($id)
	{
		return $this->assertRecordExists('EmpireRising\FactionChange:Request', $id, ['User']);
	}

	/**
	 * @return \EmpireRising\FactionChange\Service\Request\Resolve
	 */
	protected function getResolver($request)
	{
		return $this->service('EmpireRising\FactionChange:Request\Resolve', $request);
	}

	/**
	 * @return \EmpireRising\FactionChange\Repository\FactionRequest
	 */
	protected function getFactionRepo()
	{
		return $this->repository('EmpireRising\FactionChange:FactionRequest');
	}

	public static function getActivityDetails(array $activities)
	{
		return 'Managing faction membership';
	}
}
