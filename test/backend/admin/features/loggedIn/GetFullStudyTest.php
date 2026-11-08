<?php

namespace backend\admin\features\loggedIn;

use backend\exceptions\PageFlowException;
use PHPUnit\Framework\MockObject\Stub;
use testConfigs\BaseLoggedInPermissionTestSetup;

require_once __DIR__ . '/../../../../autoload.php';

/**
 * GetFullStudy returns a study's whole config, including its access keys. It must only work
 * for accounts that have a role in that specific study.
 */
class GetFullStudyTest extends BaseLoggedInPermissionTestSetup {
	private $isAdmin = false;
	private $permissions = [];
	
	protected function setUpAccountStoreObserver(): Stub {
		$observer = parent::setUpAccountStoreObserver();
		$observer
			->method('getPermissions')
			->willReturnCallback(function() {
				return array_merge(['admin' => $this->isAdmin], $this->permissions);
			});
		return $observer;
	}
	
	function test_admin_may_read_any_study() {
		$this->isAdmin = true;
		$this->assertInstanceOf(GetFullStudy::class, new GetFullStudy());
	}
	
	function test_roles_in_this_study_are_enough() {
		foreach(['read', 'write', 'msg', 'reward'] as $role) {
			$this->permissions = [$role => [$this->studyId]];
			$this->assertInstanceOf(GetFullStudy::class, new GetFullStudy(), $role);
		}
	}
	
	function test_account_without_any_role_is_rejected() {
		$this->permissions = [];
		$this->expectException(PageFlowException::class);
		$this->expectExceptionMessage('No permission');
		new GetFullStudy();
	}
	
	function test_roles_in_another_study_do_not_count() {
		foreach(['read', 'write', 'msg', 'reward'] as $role) {
			$this->permissions = [$role => [$this->studyId + 1]];
			try {
				new GetFullStudy();
				$this->fail("A '$role' permission for another study was accepted");
			}
			catch(PageFlowException $e) {
				$this->assertEquals('No permission', $e->getMessage());
			}
		}
	}
	
	function test_create_permission_alone_does_not_grant_access_to_existing_studies() {
		$this->permissions = ['create' => true];
		$this->expectException(PageFlowException::class);
		new GetFullStudy();
	}
	
	function test_missing_study_id() {
		$this->isAdmin = true;
		$this->setPost();
		$this->expectExceptionMessage('Missing study id');
		new GetFullStudy();
	}
}
