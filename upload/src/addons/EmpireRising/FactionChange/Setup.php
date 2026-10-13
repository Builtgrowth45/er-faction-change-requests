<?php

namespace EmpireRising\FactionChange;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	public function installStep1()
	{
		$this->schemaManager()->createTable('xf_er_faction_request', function (Create $table)
		{
			$table->addColumn('request_id', 'int')->autoIncrement();
			$table->addColumn('user_id', 'int');
			$table->addColumn('request_date', 'int');
			$table->addColumn('ign', 'varchar', 100);
			$table->addColumn('faction', 'varchar', 50);
			// "rank" is a reserved word in MySQL 8, hence faction_rank.
			$table->addColumn('faction_rank', 'tinyint')->unsigned()->setDefault(0);
			$table->addColumn('department', 'varchar', 100)->setDefault('');
			$table->addColumn('note', 'varchar', 500)->setDefault('');
			$table->addColumn('status', 'enum')->values(['pending', 'approved', 'rejected', 'cancelled'])->setDefault('pending');
			$table->addColumn('resolved_user_id', 'int')->setDefault(0);
			$table->addColumn('resolved_date', 'int')->setDefault(0);
			$table->addColumn('reject_reason', 'varchar', 255)->setDefault('');
			// Field values and secondary groups as they were just before approval, for audit or a manual revert.
			$table->addColumn('previous_values', 'mediumblob')->nullable();
			$table->addKey(['user_id', 'status']);
			$table->addKey(['status', 'faction']);
		});
	}

	public function installStep2()
	{
		// Anyone who can start threads can ask for a change. Approval permissions are left for the
		// admin to grant: "approve any" for staff, "approve own faction" for the Faction Leader group.
		$this->applyGlobalPermission('erFaction', 'request', 'forum', 'postThread');
	}

	public function postInstall(array &$stateChanges)
	{
		$this->app->jobManager()->enqueueUnique('permissionRebuild', 'XF:PermissionRebuild');
	}

	public function uninstallStep1()
	{
		$this->schemaManager()->dropTable('xf_er_faction_request');
	}
}
