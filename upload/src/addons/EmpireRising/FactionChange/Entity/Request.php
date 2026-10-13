<?php

namespace EmpireRising\FactionChange\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null $request_id
 * @property int $user_id
 * @property int $request_date
 * @property string $ign
 * @property string $faction
 * @property int $faction_rank
 * @property string $department
 * @property string $note
 * @property string $status
 * @property int $resolved_user_id
 * @property int $resolved_date
 * @property string $reject_reason
 * @property array $previous_values
 *
 * GETTERS
 * @property string $faction_label
 * @property string $rank_title
 * @property string $status_label
 *
 * RELATIONS
 * @property \XF\Entity\User $User
 * @property \XF\Entity\User $Resolver
 */
class Request extends Entity
{
	public function getFactionLabel(): string
	{
		return $this->getFactionRepo()->getFactionLabel($this->faction);
	}

	public function getRankTitle(): string
	{
		return $this->getFactionRepo()->getRankTitle($this->faction, $this->faction_rank);
	}

	public function getStatusLabel(): string
	{
		switch ($this->status)
		{
			case 'approved': return 'Approved';
			case 'rejected': return 'Rejected';
			case 'cancelled': return 'Cancelled';
			default: return 'Waiting for approval';
		}
	}

	public function isPending(): bool
	{
		return $this->status === 'pending';
	}

	/**
	 * @return \EmpireRising\FactionChange\Repository\FactionRequest
	 */
	protected function getFactionRepo()
	{
		return $this->repository('EmpireRising\FactionChange:FactionRequest');
	}

	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_er_faction_request';
		$structure->shortName = 'EmpireRising\FactionChange:Request';
		$structure->primaryKey = 'request_id';
		$structure->columns = [
			'request_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'user_id' => ['type' => self::UINT, 'required' => true],
			'request_date' => ['type' => self::UINT, 'default' => \XF::$time],
			'ign' => ['type' => self::STR, 'maxLength' => 100, 'required' => true],
			'faction' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
			'faction_rank' => ['type' => self::UINT, 'max' => 7, 'default' => 0],
			'department' => ['type' => self::STR, 'maxLength' => 100, 'default' => ''],
			'note' => ['type' => self::STR, 'maxLength' => 500, 'default' => ''],
			'status' => ['type' => self::STR, 'default' => 'pending',
				'allowedValues' => ['pending', 'approved', 'rejected', 'cancelled']
			],
			'resolved_user_id' => ['type' => self::UINT, 'default' => 0],
			'resolved_date' => ['type' => self::UINT, 'default' => 0],
			'reject_reason' => ['type' => self::STR, 'maxLength' => 255, 'default' => ''],
			'previous_values' => ['type' => self::JSON_ARRAY, 'default' => [], 'nullable' => true],
		];
		$structure->getters = [
			'faction_label' => true,
			'rank_title' => true,
			'status_label' => true,
		];
		$structure->relations = [
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true,
			],
			'Resolver' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => [['user_id', '=', '$resolved_user_id']],
				'primary' => true,
			],
		];

		return $structure;
	}
}
