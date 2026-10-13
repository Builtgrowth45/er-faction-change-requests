<?php

namespace EmpireRising\FactionChange\Repository;

use EmpireRising\FactionChange\Entity\Request;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;

class FactionRequest extends Repository
{
	const MAX_RANK = 7;

	protected $factionMap;

	/**
	 * Parses the "Factions" option. One faction per line:
	 *   key|label|user group id|rank title R0,rank title R1,...,rank title R7
	 * The key must equal the choice key of the faction profile field (the faction-xxx CSS class).
	 * A user group id of 0 means "don't touch user groups for this faction".
	 *
	 * @return array key => ['key', 'label', 'group_id', 'ranks']
	 */
	public function getFactionMap(): array
	{
		if ($this->factionMap !== null)
		{
			return $this->factionMap;
		}

		$map = [];
		$raw = (string)$this->options()->erFactionMap;
		foreach (preg_split('/\r?\n/', $raw) as $line)
		{
			$line = trim($line);
			if ($line === '' || $line[0] === '#')
			{
				continue;
			}

			$parts = array_map('trim', explode('|', $line));
			if ($parts[0] === '')
			{
				continue;
			}

			$ranks = [];
			if (isset($parts[3]) && $parts[3] !== '')
			{
				$ranks = array_map('trim', explode(',', $parts[3]));
			}

			$map[$parts[0]] = [
				'key' => $parts[0],
				'label' => isset($parts[1]) && $parts[1] !== '' ? $parts[1] : $parts[0],
				'group_id' => isset($parts[2]) ? max(0, intval($parts[2])) : 0,
				'ranks' => $ranks,
			];
		}

		$this->factionMap = $map;
		return $map;
	}

	public function getFactionLabel(string $key): string
	{
		$map = $this->getFactionMap();
		return isset($map[$key]) ? $map[$key]['label'] : $key;
	}

	public function getRankTitle(string $faction, int $rank): string
	{
		$map = $this->getFactionMap();
		if (isset($map[$faction]['ranks'][$rank]) && $map[$faction]['ranks'][$rank] !== '')
		{
			return $map[$faction]['ranks'][$rank];
		}
		return 'R' . $rank;
	}

	/**
	 * The value written to the rank profile field, from the "Rank field format" option.
	 */
	public function formatRankValue(Request $request): string
	{
		$format = trim((string)$this->options()->erFactionRankFormat);
		if ($format === '')
		{
			$format = '{title}';
		}

		$value = strtr($format, [
			'{title}' => $request->rank_title,
			'{rank}' => (string)$request->faction_rank,
			'{department}' => $request->department,
			'{faction}' => $request->faction_label,
		]);

		// Tidy separators left behind when a placeholder is empty, e.g. "Detective · ".
		return trim(preg_replace('/\s{2,}/', ' ', $value), " \t-|·,");
	}

	/**
	 * Profile field ids from the options. An empty id means "don't write this value".
	 *
	 * @return array ['ign' => id, 'faction' => id, 'rank' => id, 'department' => id]
	 */
	public function getFieldIds(): array
	{
		$options = $this->options();
		return [
			'ign' => trim((string)$options->erFactionFieldIgn),
			'faction' => trim((string)$options->erFactionFieldFaction),
			'rank' => trim((string)$options->erFactionFieldRank),
			'department' => trim((string)$options->erFactionFieldDepartment),
		];
	}

	/**
	 * The user's current identity, read from their profile fields.
	 */
	public function getCurrentValues(User $user): array
	{
		$values = ['ign' => '', 'faction' => '', 'rank' => '', 'department' => ''];
		$profile = $user->Profile;
		if (!$profile)
		{
			return $values;
		}

		$fields = $profile->custom_fields;
		foreach ($this->getFieldIds() as $key => $fieldId)
		{
			if ($fieldId === '')
			{
				continue;
			}
			$value = isset($fields[$fieldId]) ? $fields[$fieldId] : '';
			$values[$key] = is_array($value) ? implode(', ', $value) : (string)$value;
		}

		return $values;
	}

	public function getUserFaction(User $user): string
	{
		$values = $this->getCurrentValues($user);
		return $values['faction'];
	}

	public function canApproveAny(User $user): bool
	{
		return $user->user_id && $user->hasPermission('erFaction', 'approveAny');
	}

	/**
	 * Staff with "approve any" can action every request. Faction leaders with "approve own faction"
	 * can action requests to join their own faction, but never their own request.
	 */
	public function canApprove(User $user, Request $request = null): bool
	{
		if (!$user->user_id)
		{
			return false;
		}
		if ($this->canApproveAny($user))
		{
			return true;
		}
		if (!$user->hasPermission('erFaction', 'approveOwn'))
		{
			return false;
		}

		$ownFaction = $this->getUserFaction($user);
		if ($ownFaction === '')
		{
			return false;
		}
		if (!$request)
		{
			return true;
		}

		return $request->faction === $ownFaction && $request->user_id != $user->user_id;
	}

	/**
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function findPendingForApprover(User $user)
	{
		$finder = $this->finder('EmpireRising\FactionChange:Request')
			->where('status', 'pending')
			->with('User')
			->order('request_date');

		if (!$this->canApproveAny($user))
		{
			$finder->where('faction', $this->getUserFaction($user))
				->where('user_id', '<>', $user->user_id);
		}

		return $finder;
	}

	/**
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function findRecentlyResolvedForApprover(User $user)
	{
		$finder = $this->finder('EmpireRising\FactionChange:Request')
			->where('status', ['approved', 'rejected'])
			->with(['User', 'Resolver'])
			->order('resolved_date', 'DESC');

		if (!$this->canApproveAny($user))
		{
			$finder->where('faction', $this->getUserFaction($user));
		}

		return $finder;
	}

	/**
	 * @return Request|null
	 */
	public function getPendingForUser(User $user)
	{
		return $this->finder('EmpireRising\FactionChange:Request')
			->where('user_id', $user->user_id)
			->where('status', 'pending')
			->fetchOne();
	}

	/**
	 * Unix time the user may next send a request, or 0 if they can now.
	 */
	public function getCooldownEnd(User $user): int
	{
		$days = intval($this->options()->erFactionCooldownDays);
		if ($days <= 0)
		{
			return 0;
		}

		$last = $this->finder('EmpireRising\FactionChange:Request')
			->where('user_id', $user->user_id)
			->where('status', 'approved')
			->order('resolved_date', 'DESC')
			->fetchOne();
		if (!$last)
		{
			return 0;
		}

		$end = $last->resolved_date + $days * 86400;
		return $end > \XF::$time ? $end : 0;
	}
}
