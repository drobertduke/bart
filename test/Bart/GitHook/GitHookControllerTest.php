<?php
namespace Bart\GitHook;

use Bart\BaseTestCase;
use Bart\Diesel;
use Bart\Git\GitRoot;

class GitHookControllerTest extends BaseTestCase
{
	const POST_RECEIVE_PATH = 'hook/post-receive.d';
	const POST_RECEIVE_REAL_PATH = '/var/lib/gitosis/monty.git/hooks/post-receive.d';
	const POST_RECEIVE_SCRIPT = 'hook/post-receive.d/bart-runner';

	const MASTER_REF = '/refs/heads/master';
	const JIRA_REF = '/refs/heads/jira';
	const START_HASH = 'startHash';
	const END_HASH = 'endHash';

	public function testScriptNameParsing()
	{
		$stubShell = $this->getMock('\Bart\Shell');
		$stubShell->expects($this->once())
			->method('realpath')
			->with(self::POST_RECEIVE_PATH)
			->will($this->returnValue(self::POST_RECEIVE_REAL_PATH));

		$this->registerDiesel('\Bart\Shell', $stubShell);

		// This value won't be used during this test
		$this->registerDiesel('\Bart\Git\GitRoot', null);

		$runner = GitHookController::createFromScriptName(self::POST_RECEIVE_SCRIPT);

		$this->assertEquals('monty.post-receive', "$runner", 'hook runner to string');
	}

	public function testProcessRevisionWithNoRefsIncluded()
	{
		$stdInArray = [self::START_HASH . ' ' . self::END_HASH . ' ' . self::MASTER_REF];
		$revList = ['hashOne'];
		$validRefs = [];

		// No Refs Included
		$numValidRefs = 0;

		$this->runProcessRevisionTest($stdInArray, $revList, $validRefs, $numValidRefs);
	}

	public function testProcessRevisionWithOneRefIncluded()
	{
		$stdInArray = [self::START_HASH . ' ' . self::END_HASH . ' ' . self::MASTER_REF];
		$revList = ['hashOne'];
		$validRefs = [self::MASTER_REF];

		// The one ref in $stdInArray is also in $validRefs
		$numValidRefs = 1;

		$this->runProcessRevisionTest($stdInArray, $revList, $validRefs, $numValidRefs);
	}

	public function testProcessRevisionWithOneRefOutOfTwoIncluded()
	{
		$stdInArray = [
			self::START_HASH . ' ' . self::END_HASH . ' ' . self::MASTER_REF,
			self::START_HASH . ' ' . self::END_HASH . ' ' . self::JIRA_REF
		];
		$revList = ['hashOne'];
		$validRefs = [self::MASTER_REF];

		// Only one of the two refs in $stdInArray is actually in $validRefs
		$numValidRefs = 1;

		$this->runProcessRevisionTest($stdInArray, $revList, $validRefs, $numValidRefs);
	}

	public function testProcessRevisionWithTwoRefsIncluded()
	{
		$stdInArray = [
			self::START_HASH . ' ' . self::END_HASH . ' ' . self::MASTER_REF,
			self::START_HASH . ' ' . self::END_HASH . ' ' . self::JIRA_REF
		];
		$revList = ['hashOne'];
		$validRefs = [self::MASTER_REF, self::JIRA_REF];

		// Both refs in $stdInArray are also in $validRefs
		$numValidRefs = 2;

		$this->runProcessRevisionTest($stdInArray, $revList, $validRefs, $numValidRefs);
	}

	public function testProcessRevisionWithIncorrectRefIncluded()
	{
		$stdInArray = [self::START_HASH . ' ' . self::END_HASH . ' ' . self::MASTER_REF];
		$revList = ['hashOne'];
		$validRefs = [self::JIRA_REF];

		// The ref included in $stdInArray is different from ref in $validRefs
		$numValidRefs = 0;

		$this->runProcessRevisionTest($stdInArray, $revList, $validRefs, $numValidRefs);
	}

	public function testProcessRevisionWithMultipleIncludedRefsAndRevs()
	{
		$stdInArray = [self::START_HASH . ' ' . self::END_HASH . ' ' . self::MASTER_REF, self::START_HASH . ' ' . self::END_HASH . ' ' . self::JIRA_REF];
		$revList = ['hashOne', 'hashTwo', 'hashThree'];
		$validRefs = [self::MASTER_REF, self::JIRA_REF];

		// Both refs in $stdInArray are also in $validRefs
		$numValidRefs = 2;

		$this->runProcessRevisionTest($stdInArray, $revList, $validRefs, $numValidRefs);
	}

	/**
	 * @param string[] $stdInArray Array of standard input values
	 * @param string[] $revList Array of revisions
	 * @param string[] $validRefs Array of all valid refs
	 * @param int $numValidRefs Number of valid refs in the standard input array that are actually in $validRefs
	 */
	private function runProcessRevisionTest(array $stdInArray, array $revList, array $validRefs, $numValidRefs)
	{
		$numInputs = count($stdInArray);
		$numRevs = count($revList);

		$this->shmockAndDieselify('\Bart\Shell', function($shell) use($stdInArray) {
			$shell->realpath(self::POST_RECEIVE_PATH)->once()->return_value(self::POST_RECEIVE_REAL_PATH);
			$shell->std_in()->once()->return_value($stdInArray);
		});

		$this->shmockAndDieselify('\Bart\Git\GitRoot', function($gitRoot) {
			$gitRoot->getCommandResult()->never();
		}, true);

		$this->shmockAndDieselify('\Bart\Git', function($git) use($revList, $numInputs) {
			$git->getRevList(self::START_HASH, self::END_HASH)->times($numInputs)->return_value($revList);
		}, true);

		// The number of runs for $gitHookConfig->getValidRefs() depend on the total number of
		// inputs in the standard input array and the number of revisions
		$validRefsTimes = $numInputs * $numRevs;
		$stubConfig = $this->shmock('\Bart\GitHook\GitHookConfig', function($gitHookConfig) use($validRefsTimes, $validRefs) {
			$gitHookConfig->getValidRefs()->times($validRefsTimes)->return_value($validRefs);
		}, true);

		// The number of runs for $gitCommit->message() and $postReceiveRunner->runAllActions depend on $numValidRefs
		$numValidCommits = $numValidRefs * $numRevs;
		$stubCommit = $this->shmockAndDieselify('\Bart\Git\Commit', function($gitCommit) use($numValidCommits) {
			$gitCommit->message()->times($numValidCommits)->return_value('NOT IMPORTANT');
		}, true);


		$stubRunner = $this->shmock('\Bart\GitHook\PostReceiveRunner', function($postReceiveRunner) use($numValidCommits) {
			$postReceiveRunner->runAllActions()->times($numValidCommits);
		}, true);

		// Explicitly register GitHookConfig and PostReceiveRunner stubs so that we can
		// ...assert the constructor args are what we expect
		Diesel::registerInstantiator('\Bart\GitHook\GitHookConfig',
			function($commit) use($stubCommit, $stubConfig) {
				$this->assertSame($commit, $stubCommit);
				return $stubConfig;
			});

		Diesel::registerInstantiator('\Bart\GitHook\PostReceiveRunner',
			function($commit) use($stubCommit, $stubRunner) {
				$this->assertSame($commit, $stubCommit);
				return $stubRunner;
			});

		// Create the controller and verify the mocks
		$controller = GitHookController::createFromScriptName(self::POST_RECEIVE_SCRIPT);
		$controller->run();
	}
}

