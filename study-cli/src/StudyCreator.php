<?php

declare(strict_types=1);

namespace StudyCli;

use backend\admin\features\createPermission\CreateStudy;
use backend\Configs;
use backend\exceptions\CriticalException;
use backend\exceptions\PageFlowException;
use backend\Main;
use backend\Paths;
use backend\Permission;
use RuntimeException;

/**
 * Creates a study on this server the way the admin editor does: the spec becomes the configs the editor would post (StudyFactory),
 * and those go through the same CreateStudy / SaveStudy features as the editor's request, as an account of the server. Access
 * keys and questionnaire ids are checked against the other studies first, and the stored study is audited afterwards.
 *
 * ```php
 * $created = StudyCreator::forThisServer()->create($spec, 'admin');
 * echo $created->studyId();
 * ```
 */
final class StudyCreator {
	public function __construct(
		private StudyFactory $factory,
		private StudyAudit $audit,
		private StudyIdSource $ids
	) {
	}

	/** A creator that stamps the versions of this server into the studies it makes. */
	public static function forThisServer(?StudyIdSource $ids = null): self {
		$versionFile = Paths::FILE_SERVER_VERSION;
		$packageVersion = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
		if($packageVersion === '')
			throw new RuntimeException("The server's version file ($versionFile) is missing or empty, so the package version cannot be stamped into the study. Run this on the server, not in a source checkout.");
		$validator = new StudyValidator(Main::SERVER_VERSION);
		return new self(new StudyFactory($packageVersion, Main::SERVER_VERSION, $validator), new StudyAudit($validator), $ids ?? new StoreStudyIdSource());
	}

	/**
	 * @param string|null $accountName the account that owns the study; null = the first admin account
	 * @param bool $allowSharedAccessKeys let the study use an access key another study already has (participants then see both)
	 * @throws StudySpecException when the spec cannot become a valid study, or the server refuses it. Nothing is stored then.
	 */
	public function create(array $spec, ?string $accountName = null, bool $dryRun = false, bool $allowSharedAccessKeys = false): CreatedStudy {
		// The request is made by filling in what the web server would read from the request (Main::$defaultPostInput), which the backend
		// only honours on the command line.
		if(PHP_SAPI !== 'cli')
			throw new RuntimeException('StudyCreator can only be used from the command line.');
		$account = $this->resolveAccount($accountName);
		$built = $this->factory->build($spec, $this->ids);

		$clashes = $this->audit->clashes(json_decode($built->json(), true)['_'], $built->studyId, $allowSharedAccessKeys ? StudyProblem::WARNING : StudyProblem::ERROR, false);
		if(StudyProblem::errors($clashes))
			throw new StudySpecException(array_merge($built->warnings, $clashes));
		$built = new BuiltStudy($built->studyId, $built->bundle, array_merge($built->warnings, $clashes));
		if($dryRun)
			return new CreatedStudy($built, $account, false);

		$this->save($built, $account);
		return new CreatedStudy($built, $account, true, $this->audit->auditStored($built->studyId));
	}

	/** The admin editor's CreateStudy request, made as $account. */
	private function save(BuiltStudy $built, string $account): void {
		$saved = [$_GET, $_SESSION ?? [], Main::$defaultPostInput];
		try {
			Permission::setLoggedIn($account);
			$_GET = ['study_id' => (string) $built->studyId, 'lastChanged' => (string) time()];
			Main::$defaultPostInput = $built->json();
			(new CreateStudy())->exec();
		}
		catch(PageFlowException|CriticalException $e) {
			$partlyWritten = $this->undo($built->studyId, $account);
			throw new StudySpecException([StudyProblem::error('save', "The server refused the study: {$e->getMessage()}"
				. ($partlyWritten ? " Part of study {$built->studyId} was already written; look at it with check_study.php and delete it in the admin editor." : ''))], !$partlyWritten);
		}
		finally {
			[$_GET, $_SESSION, Main::$defaultPostInput] = $saved;
		}
	}

	/**
	 * CreateStudy gives a non-admin account its rights on the study before SaveStudy runs; take them back when the save failed.
	 * @return bool whether the study's folder exists anyway
	 */
	private function undo(int $studyId, string $account): bool {
		$accounts = Configs::getDataStore()->getAccountStore();
		if(empty($accounts->getPermissions($account)['admin'])) {
			foreach(['write', 'read', 'msg', 'publish', 'reward'] as $permission)
				$accounts->removeStudyPermission($account, $studyId, $permission);
		}
		return Configs::getDataStore()->getStudyStore()->studyExists($studyId);
	}

	private function resolveAccount(?string $name): string {
		$accounts = Configs::getDataStore()->getAccountStore();
		if($name === null) {
			foreach($accounts->getAccountList() as $candidate) {
				if(!empty($accounts->getPermissions($candidate)['admin']))
					return $candidate;
			}
			throw new StudySpecException([StudyProblem::error('account', 'The server has no admin account to own the study; name one with --account')]);
		}
		if(!$accounts->doesAccountExist($name))
			throw new StudySpecException([StudyProblem::error('account', "There is no account \"$name\" on this server")]);
		$permissions = $accounts->getPermissions($name);
		if(empty($permissions['admin']) && empty($permissions['create']))
			throw new StudySpecException([StudyProblem::error('account', "\"$name\" may not create studies (it is neither an admin nor allowed to create studies)")]);
		return $name;
	}
}
