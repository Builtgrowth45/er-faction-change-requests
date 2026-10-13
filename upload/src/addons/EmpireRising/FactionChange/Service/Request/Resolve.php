<?php

namespace EmpireRising\FactionChange\Service\Request;

use EmpireRising\FactionChange\Entity\Request;
use XF\Entity\User;
use XF\PrintableException;
use XF\Service\AbstractService;

/**
 * Approves or rejects one faction change request.
 *
 * Approval writes the in-game name, faction, rank and department profile fields, swaps the
 * player's faction user group (which also swaps their faction banner and faction forum access),
 * records what it replaced, and alerts the player. Everything happens in one transaction.
 */
class Resolve extends AbstractService
{
	/** @var Request */
	protected $request;

	public function __construct(\XF\App $app, Request $request)
	{
		parent::__construct($app);
		$this->request = $request;
	}

	public function approve(User $approver)
	{
		$request = $this->request;
		$this->assertPending();

		$user = $request->User;
		if (!$user || !$user->Profile)
		{
			throw new PrintableException('This member no longer exists.');
		}

		$repo = $this->getFactionRepo();
		$map = $repo->getFactionMap();
		if (!isset($map[$request->faction]))
		{
			throw new PrintableException('The requested faction is not in the add-on options any more.');
		}
		$faction = $map[$request->faction];

		$fieldIds = $repo->getFieldIds();
		$newValues = [
			'ign' => $request->ign,
			'faction' => $request->faction,
			'rank' => $repo->formatRankValue($request),
			'department' => $request->department,
		];

		$previous = $repo->getCurrentValues($user);
		$previous['secondary_group_ids'] = $user->secondary_group_ids;

		$db = $this->db();
		$db->beginTransaction();

		try
		{
			$fields = $user->Profile->custom_fields;
			$written = [];
			foreach ($newValues as $key => $value)
			{
				$fieldId = $fieldIds[$key];
				if ($fieldId === '')
				{
					continue;
				}
				$fields->set($fieldId, $value, 'admin', true);
				$written[$fieldId] = $value;
			}
			$user->Profile->save(true, false);
			$this->assertFieldsSaved($user->user_id, $written);

			$this->swapFactionGroup($user, $map, $faction['group_id']);

			$request->status = 'approved';
			$request->resolved_user_id = $approver->user_id;
			$request->resolved_date = \XF::$time;
			$request->previous_values = $previous;
			$request->save(true, false);

			$db->commit();
		}
		catch (\Exception $e)
		{
			$db->rollback();
			throw $e;
		}

		$this->alert($approver, 'er_faction_approved', [
			'faction' => $faction['label'],
			'rank' => $newValues['rank'],
		]);
	}

	public function reject(User $approver, string $reason)
	{
		$request = $this->request;
		$this->assertPending();

		$request->status = 'rejected';
		$request->resolved_user_id = $approver->user_id;
		$request->resolved_date = \XF::$time;
		$request->reject_reason = $reason;
		$request->save();

		$this->alert($approver, 'er_faction_rejected', [
			'faction' => $request->faction_label,
			'reason' => $reason,
		]);
	}

	protected function assertPending()
	{
		if (!$this->request->isPending())
		{
			throw new PrintableException('This request has already been dealt with.');
		}
	}

	/**
	 * Removes every faction group listed in the options and adds the new one. Non-faction
	 * secondary groups (Dev Tester, Faction Leader, ...) are left alone.
	 */
	protected function swapFactionGroup(User $user, array $map, int $newGroupId)
	{
		$factionGroupIds = [];
		foreach ($map as $entry)
		{
			if ($entry['group_id'] > 0)
			{
				$factionGroupIds[] = $entry['group_id'];
			}
		}
		if (!$factionGroupIds)
		{
			return;
		}

		$groups = array_diff(array_map('intval', $user->secondary_group_ids), $factionGroupIds);
		if ($newGroupId > 0 && $newGroupId != $user->user_group_id)
		{
			$groups[] = $newGroupId;
		}
		$groups = array_values(array_unique($groups));
		sort($groups);

		$current = array_map('intval', $user->secondary_group_ids);
		sort($current);
		if ($groups === $current)
		{
			return;
		}

		$user->secondary_group_ids = $groups;
		$user->save(true, false);
	}

	/**
	 * Reads the values back from the database, so a silent failure to save a profile field
	 * aborts the whole approval instead of half-applying it.
	 */
	protected function assertFieldsSaved(int $userId, array $written)
	{
		if (!$written)
		{
			return;
		}

		$stored = $this->db()->fetchPairs('
			SELECT field_id, field_value
			FROM xf_user_field_value
			WHERE user_id = ?
		', $userId);

		foreach ($written as $fieldId => $value)
		{
			$have = isset($stored[$fieldId]) ? $stored[$fieldId] : '';
			if ((string)$have !== (string)$value)
			{
				throw new PrintableException(
					"The profile field '$fieldId' did not save (wanted '$value', found '$have'). "
					. 'Check the field id in the add-on options and that the value is allowed for that field. Nothing was changed.'
				);
			}
		}
	}

	protected function alert(User $approver, string $action, array $extra)
	{
		$user = $this->request->User;
		if (!$user)
		{
			return;
		}

		/** @var \XF\Repository\UserAlert $alertRepo */
		$alertRepo = $this->repository('XF:UserAlert');
		$alertRepo->alert(
			$user,
			$approver->user_id,
			$approver->username,
			'user',
			$user->user_id,
			$action,
			$extra
		);
	}

	/**
	 * @return \EmpireRising\FactionChange\Repository\FactionRequest
	 */
	protected function getFactionRepo()
	{
		return $this->repository('EmpireRising\FactionChange:FactionRequest');
	}
}
