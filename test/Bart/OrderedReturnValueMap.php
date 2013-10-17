<?php
/**
 * User: jpollard
 * Date: 10/16/13
 * Time: 3:30 PM
 *
 * A class for use with phpunit stubs to ensure ordered calls
 * of mocked methods with specific parameters
 *
 * The Primary use case for this is within a ->return_callback() matcher
 *
 * 		// Assume there is a class '\Foo\Ssh' with a 'run_command($command, $timeout)' method.
 *		// This method is called numerous times and you need to ensure it is passed the correct
 *		// $command and $timeout as well as return the expected response.
 *
 *      public function test_Shmock_With_OrderedReturnValueMap_syntax()
 *		{
 * 			// we need to pass the PHPUnit object in, but can't use 'this'
 * 			$phpu = $this;
 *
 * 			// Create an array of arrays. each sub array contains and ordered element of the
 * 			// expected parameters the mocked method expects to receive. The LAST element of the
 * 			// array is the response that will be returned.
 * 			$run_command_expects = new \Bart\OrderedReturnValueMap($phpu, array(
 *				array("first command", 10, "first response"),
 * 				array("second command", 10, "second response)
 * 			));
 *
 *
 *			$ssh_mock = $this->shmock('\Foo\Ssh',
 * 				function($shmock) use ($phpu, $run_expects)
 *				{
 *					$shmock->run_command()->any()->will(function(\PHPUnit_Framework_MockObject_Invocation $invocation) use ($run_command_expects)
 *					{
 *						return $run_command_expects->check_request($invocation->parameters);
 *					});
 *				}
 * 			);
 *
 *			//use your mock object as normal, then at the end run verify() to ensure ALL expected calls to the mocked method were made
 *			$run_command_expects->verify();
 *		}
 *
 *
 */

namespace Bart;


class OrderedReturnValueMap
{

	/** @var array  */
	protected $requests;

	/** @var  \Bart\BaseTestCase */
	protected $phpu;

	/**
	 * @param \Bart\BaseTestCase $phpu
	 * @param mixed[] $requests
	 * @throws \Exception if $requests is not an array
	 */
	public function __construct($phpu, $requests)
	{
		$this->phpu = $phpu;
		if(!is_array($requests))
		{
			throw new \Exception("Requests must be an array");
		}

		$this->requests = $requests;
	}

	/**
	 * Check parameters against the next expected set of parameters and get the associated response back.
	 * @param mixed[] $parameters array of expected parameters and associated response
	 * @return mixed
	 */
	public function check_request($parameters)
	{
		$expected_request = array_shift($this->requests);

		$response = array_pop($expected_request);
		$this->phpu->assertEquals($expected_request, $parameters, "OrderedReturnValueMap Error: Actual parameters do not match expected");

		return $response;
	}

	/**
	 * Verify All requests were executed
	 */
	public function verify()
	{
		$this->phpu->assertEmpty($this->requests, "OrderedReturnValueMap Error: Not all requests were completed");
	}
}