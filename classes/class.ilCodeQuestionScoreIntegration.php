<?php

require_once './Modules/TestQuestionPool/classes/class.assQuestion.php';
require_once './Modules/Test/classes/inc.AssessmentConstants.php';
require_once './Modules/TestQuestionPool/interfaces/interface.ilObjQuestionScoringAdjustable.php';
require_once './Modules/TestQuestionPool/interfaces/interface.ilObjFileHandlingQuestionType.php';
// fred: added for logging
include_once "./Modules/Test/classes/class.ilObjAssessmentFolder.php";
// fred.

/**
 * Basic class for EST import/Export
 *
 * @author Frank Bauer <frank.bauer@fau.de>
 * @version $Id$
 *
 */
class ilCodeQuestionScoreIntegration
{
	/** @var ilObjTest $testObj */
	protected $testObj;

	/** @var ilCodeQuestionScoreIntegrationPlugin $plugin */
	protected $plugin;

	protected \ILIAS\TestQuestionPool\QuestionInfoService $questioninfo;

	protected ilLanguage $lng;
	protected string $initiator_name;
	protected int $initiator_id;

	/**
	 * ilCodeQuestionScoreIntegration constructor.
	 *
	 * @param ilObjTest $a_test_obj
	 * @param ilCodeQuestionScoreIntegration $a_plugin
	 */
	public function __construct($a_test_obj, $a_plugin)
	{
		global $lng, $DIC;
		$lng->loadLanguageModule('assessment');
		$this->testObj = $a_test_obj;
		$this->plugin = $a_plugin;
		$this->questioninfo = $DIC->testQuestionPool()->questionInfo();


		$this->lng = $DIC->language();
		$this->initiator_name = $DIC->user()->getFullname() . " (" . $DIC->user()->getLogin() . ")";
		$this->initiator_id = $DIC->user()->getId();
	}

	private function log($message)
	{
		global $DIC;
		$ilLog = $DIC->logger()->root();
		$ilLog->info($message);
	}

	private function debug($message)
	{
		global $DIC;
		$ilLog = $DIC->logger()->root();
		$ilLog->debug($message);
	}

	public static function initPluginObject(string $plugin_name): ilPlugin|null
	{
		global $DIC;
		$ilLog = $DIC->logger()->root();

		try {
			$component_repository = $DIC["component.repository"];
			$component_factory = $DIC["component.factory"];
			$info = $component_repository->getPluginByName($plugin_name);

			$plugin_obj = $component_factory->getPlugin($info->getId());

			if (!is_null($info) && $info->isActive()) {
				return $plugin_obj;
			} else {
				throw new ilPluginException($plugin_name . ' plugin is not active');
			}
		} catch (ilPluginException $e) {
			$ilLog->write("Error loading Plugin " . $plugin_name . ": " . $e->getMessage(), $ilLog->ERROR);
		}

		return null;
	}

	// fred: new function logAction
	function logAction($logtext = "", $question_id = "")
	{
		global $ilUser;
		if (ilObjAssessmentFolder::_enabledAssessmentLogging()) {
			ilObjAssessmentFolder::_addLog($ilUser->getId(), $this->testObj->getId(), $logtext, $question_id, NULL, TRUE, $this->testObj->getRefId());
		}
	}
	// fred.

	function updatePoints($active_fi, $question_fi, $pass, $reachedPoints, $maxPoints, $comment = NULL, $forcePoints = false)
	{
		global $ilDB;
		$setManScoringDone = $_POST['set_manscoring_done'] == 'set';

		$this->setReachedPointsOnly(
			$active_fi,
			$question_fi,
			$reachedPoints,
			$maxPoints,
			$pass,
			1,
			$this->testObj->areObligationsEnabled(),
			$forcePoints
		);

		global $ilUser;
		$logtext = sprintf(
			$this->plugin->txt('log_import_score'),
			$ilUser->getFullname() . " (" . $ilUser->getLogin() . ")",
			$reachedPoints,
			ilObjTestAccess::_getParticipantData($active_fi),
			$this->questioninfo->getQuestionTitle($question_fi) //assQuestion::_getQuestionTitle($question_fi)			
		);

		$this->logAction($logtext, $question_fi);
		// fred.

		if (!is_null($comment)) {
			include_once "./Services/AdvancedEditing/classes/class.ilObjAdvancedEditing.php";

			$feedback = ilUtil::stripSlashes(
				$comment,
				false,
				ilObjAdvancedEditing::_getUsedHTMLTagsAsString("assessment")
			);
			$this->testObj->saveManualFeedback(
				$active_fi,
				$question_fi,
				$pass,
				$feedback
			);
		}

		if ($setManScoringDone) {
			ilTestService::setManScoringDone($active_fi, true);
		}
	}

	/**
	 * frank: Copied from ILIAS/Modules/Test/classes/class.ilTestScoring.php
	 * 
	 * This is an optimized version of \assQuestion::_setReachedPoints that only executes updates in the database if
	 * necessary. In addition, unlike the original, this method does NOT update the test cache, so this must also be called
	 * afterward.
	 *
	 * @see assQuestion::_setReachedPoints
	 */
	public function updateReachedPoints(int $active_id, int $question_id, float $old_points, float $points, float $max_points, int $pass, int $is_manual = 1, $forcePoints = false): void
	{
		global $ilDB;

		// Only update the test results if necessary
		$has_changed = $old_points !== $points || $forcePoints;
		if ($has_changed && $points <= $max_points) {
			$ilDB->update(
				'tst_test_result',
				[
					'points' => ['float', $points],
					'tstamp' => ['integer', time()],
					'manual' => ['integer', $is_manual]
				],
				[
					'active_fi' => ['integer', $active_id],
					'question_fi' => ['integer', $question_id],
					'pass' => ['integer', $pass]
				]
			);
		}

		// Always update the pass result as the maximum points might have changed
		$data = ilObjTest::_getQuestionCountAndPointsForPassOfParticipant($active_id, $pass);
		$values = [
			'maxpoints' => ['float', $data['points']],
			'tstamp' => ['integer', time()],
		];
		
		if ($has_changed) {
			$result = $ilDB->queryF(
				'SELECT SUM(points) reachedpoints FROM tst_test_result WHERE active_fi = %s AND pass = %s',
				['integer', 'integer'],
				[$active_id, $pass]
			);
			$values['points'] = ['float', $result->fetchAssoc()['reachedpoints'] ?? 0.0];
			
			$ilDB->update(
				'tst_pass_result',
				$values,
				['active_fi' => ['integer', $active_id], 'pass' => ['integer', $pass]]
			);
		} else {
			$ilDB->update(
				'tst_pass_result',
				$values,
				['active_fi' => ['integer', $active_id], 'pass' => ['integer', $pass]]
			);
		}

		ilCourseObjectiveResult::_updateObjectiveResult(ilObjTest::_getUserIdFromActiveId($active_id), $active_id, $question_id);
		$this->testObj->updateTestResultCache($active_id);
		
		if (ilObjAssessmentFolder::_enabledAssessmentLogging()) {
			$msg = 'CodeQuestionScoreIntegration changed Points old=%f new=%f by %s';
			$msg = sprintf(
				$msg,
				$old_points,
				$points,
				$this->initiator_name
			);
			ilObjAssessmentFolder::_addLog(
				$this->initiator_id,
				$this->testObj->getId(),
				$msg,
				$question_id
			);
		}
	}

	// fred: copied from assQuestion and modified
	/**
	 * Only set the points, a learner has reached answering the question
	 * Don't update the pass result or the the result cache
	 *
	 * @param integer $user_id The database ID of the learner
	 * @param integer $test_id The database Id of the test containing the question
	 * @param integer $points The points the user has reached answering the question
	 * @return boolean true on success, otherwise false
	 * @access public
	 */
	protected function setReachedPointsOnly($active_id, $question_id, $points, $maxpoints, $pass, $isManualScoring, $obligationsEnabled, $forcePoints = false)
	{
		global $ilDB;

		if ($points <= $maxpoints) {
			if (is_null($pass)) {
				$pass = assQuestion::_getSolutionMaxPass($question_id, $active_id);
			}

			// retrieve the already given points
			$old_points = -1;
			$manual = ($isManualScoring) ? 1 : 0;
			if (!$forcePoints) {
				$result = $ilDB->queryF(
					"SELECT points FROM tst_test_result WHERE active_fi = %s AND question_fi = %s AND pass = %s",
					array('integer', 'integer', 'integer'),
					array($active_id, $question_id, $pass)
				);
				
				$rowsnum = $result->numRows();
				if ($rowsnum) {
					$row = $ilDB->fetchAssoc($result);
					$old_points = $row["points"];
				}
			}

			$this->updateReachedPoints($active_id, $question_id, $old_points, $points, $maxpoints, $pass, $manual, $forcePoints);

			if ($old_points != $points || !$rowsnum) {
				return TRUE;
			}

			return FALSE;
		} else {
			return FALSE;
		}
	}
	// fred.

	function processZipFile($zipFile)
	{
		global $ilDB;

		$zip = new ZipArchive();
		$zip->open($zipFile);
		$result = array(
			"numberOfFiles" => $zip->numFiles,
			"files" => array(),
			"metaFiles" => array(),
			"unparsableEntries" => array(),
			"ignoredFiles" => array(),
			"invalidComment" => array(),
			"wrongTest" => array()
		);

		for ($i = 0; $i < $zip->numFiles; $i++) {
			//echo "index: $i\n";
			$item = $zip->statIndex($i);

			$filePath = trim($item['name']) . '';

			if (substr($filePath, 0, 9) == '__MACOSX/') {
				$result['metaFiles'][] = $item['name'];
				continue;
			} else if (substr($filePath, 0, 1) == '.' || !(strpos($filePath, '/.') === false)) {
				$result['metaFiles'][] = $item['name'];
				continue;
			}

			$matches = array();
			preg_match_all(':test-([0-9]+)/question-([0-9]+)/solution-([0-9]+)-([0-9]+)-([0-9]+)-([0-9]+)-(.*)/(.*):', $filePath, $matches);

			if (count($matches) != 9 || count($matches[0]) == 0 || count($matches[8]) == 0 || trim($matches[8][0]) == '') {
				$result['unparsableEntries'][] = $item['name'];
				continue;
			}
			$fileName = trim($matches[8][0]);

			$obj = array(
				"path" => $filePath,
				"file" => $fileName,
				"testID" => (int) $matches[1][0],
				"questionID" => (int) $matches[2][0],
				"solutionID" => (int) $matches[3][0],
				"activeID" => (int) $matches[4][0],
				"pass" => (int) $matches[5][0],
				"userID" => (int) $matches[6][0],
				"login" => trim($matches[7][0])
			);

			if (strtolower($obj["file"]) == 'comment') {
				$obj['rawContent'] = $zip->getFromIndex($i) ?? '';
				$obj['rawContent'] = trim(str_replace("\r", "", $obj['rawContent']));

				preg_match_all('/.*\:\s*(-?[0-9]+([.,][0-9]+)?)\s*\n([\s\S]*)/', $obj['rawContent'], $matches);
				//$this->log("matches: " . print_r($matches, true));
				if ($this->testObj->getID() != $obj['testID']) {
					$result['wrongTest'][] = $obj;
				} else if (count($matches) != 4 || count($matches[0]) == 0) {

					//check the first line for points		
					$first_line = strtok($obj['rawContent'], "\n");
					//$this->log("falback on first line: " . $first_line);	
					preg_match_all('/\s*(?:POINTS|Points|points|score|SCORE|Score)\s*\:\s*(-?[0-9]+([.,][0-9]+)?)\s*/', $first_line, $matches);
					//$this->log("fallback matches: " . print_r($matches, true));
					if (count($matches) != 3 || count($matches[0]) == 0) {
						$result['invalidComment'][] = $obj;
					} else {
						$obj['points'] = (float) str_replace(',', '.', $matches[1][0]);
						$obj['comment'] = '';
						$obj['stored'] = false;
						$result['files'][] = $obj;
					}
				} else {
					$obj['points'] = (float) str_replace(',', '.', $matches[1][0]);
					$obj['comment'] = '<pre style="font-family:monospace">' . trim($matches[3][0]) . '</pre>';
					$obj['stored'] = false;
					$result['files'][] = $obj;
				}
			} else {
				$result['ignoredFiles'][] = $obj;
			}
		}
		$this->storeInfo($result);

		//we may have to do this only once!
		require_once './Modules/Test/classes/class.ilTestScoring.php';
		$scorer = new ilTestScoring($this->testObj, $ilDB);
		$scorer->setPreserveManualScores(true);
		$scorer->recalculateSolutions();

		$logtext = $this->plugin->txt('log_recalculated_solutions');
		$this->logAction($logtext);

		return $result;
	}

	private function storeInfo(&$zipResults)
	{
		global $lng;
		$passOverride = $_POST['pass_override'] == 'ov';
		$forcePoints = $_POST['force_points'] == 'force';
		$data = [];
		if ($passOverride) {
			$data = $this->testObj->getCompleteEvaluationData(TRUE);
		}
		$questions = [];
		foreach ($zipResults['files'] as &$obj) {
			$objQuestion = NULL;
			if (isset($questions[$obj["questionID"]])) {
				$objQuestion = $questions[$obj["questionID"]];
			}

			if (!$objQuestion) {
				$objQuestion = $this->testObj->_instanciateQuestion($obj["questionID"]);
				$questions[$obj["questionID"]] = $objQuestion;
			}
			$solution = null;
			$pointPass = 0; //$solution['pass'];
			if ($passOverride) {
				$userInfo = $data->getParticipant($obj["activeID"]);
				$pointPass = $userInfo->getScoredPass();
			}
			if (method_exists($objQuestion, 'getExportSolution')) {
				$solution = $objQuestion->getExportSolution($obj["activeID"], $obj["pass"]);
			} else {
				$solutions = $objQuestion->getSolutionValues($obj["activeID"], $obj["pass"]);
				if (count($solutions) > 0)
					$solution = $solutions[count($solutions) - 1];
			}
			if ($solution != null) {
				if (
					$solution['solution_id'] == $obj["solutionID"] &&
					$solution['active_fi'] == $obj["activeID"] &&
					$solution['question_fi'] == $obj["questionID"] &&
					$solution['pass'] == $obj["pass"]
				) {
					$this->updatePoints($obj["activeID"], $obj["questionID"], $obj["pass"], $obj["points"], $objQuestion->getPoints(), $obj["comment"], $forcePoints);

					if ($obj["pass"] != $pointPass) {
						$cmt = '[' . $this->plugin->txt('pass_override_label') . ': ' . ($obj["pass"] + 1) . ']\n\n' . $obj["comment"];
						$this->updatePoints($obj["activeID"], $obj["questionID"], $pointPass, $obj["points"], $objQuestion->getPoints(), $cmt, $forcePoints);
					}
					$obj['stored'] = true;
				} else {
					$obj['error'] = $this->plugin->txt('error_inconsistent_id');
				}
			} else {
				$obj['error'] = $this->plugin->txt('error_incompatible_question');
			}
		}
	}

	function getRandomSet($objQuestion, $active_id, $pass)
	{
		$stored = $objQuestion->getSolutionValuesOrInit($active_id, $pass, true, false, false);
		$rid = -1;
		if (isset($stored['value2']) && isset($stored['value2']->rid))
			$rid = $stored['value2']->rid;
		return $objQuestion->blocks()->getRandomSet($rid);
	}

	function justAnswers($objQuestion, $solution, $trimall = false)
	{
		if (method_exists($objQuestion, 'getJustAnswers')) {
			return $objQuestion->getJustAnswers($solution, $trimall);
		}
		$blocks = $objQuestion->blocks->getCombinedBlocks($solution['value2'], true, $solution['value1']);

		$res = '';
		for ($i = 0; $i < count($blocks); $i++) {
			$t = $objQuestion->blocks[$i]->getType();
			if ($t == assCodeQuestionBlockTypes::SolutionCode) {
				if (isset($blocks[$i])) {
					if ($trimall) {
						$res .= trim($blocks[$i]) . "\n";
					} else {
						$res .= $blocks[$i] . "\n";
					}
				}
			}
		}
		return $res;
	}

	function buildZIP($zipFile)
	{
		$data = $this->testObj->getCompleteEvaluationData(TRUE);

		$zip = new ZipArchive();
		if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
			return "cannot open <$tempBase>\n";
		}

		$tempBase = sprintf('./test-%06d', $this->testObj->getId());
		$ignoreEmpty = $_POST['ignoreEmpty'] == 1;
		$autoFileName = $_POST['autoFileName'] == 1;
		foreach ($data->getParticipants() as $active_id => $userdata) {

			// Do something with the participants				
			$pass = $userdata->getScoredPass();
			$opass = $pass;
			$questions = $userdata->getQuestions($pass);

			if (!is_array($questions))
				continue;
			foreach ($questions as $question) {
				$questionBase = $tempBase . '/' . sprintf("question-%06d", $question["id"]);
				$objQuestion = NULL;
				if (isset($question["id"]) && isset($questions[$question["id"]])) {
					$objQuestion = $questions[$question["id"]];
				}

				if (!$objQuestion) {
					$objQuestion = $this->testObj->_instanciateQuestion($question["id"]);
					$questions[$question["id"]] = $objQuestion;
				}
				if (method_exists($objQuestion, 'getClozeText')) {
					$res = $this->jsonFromClozeQuestion($objQuestion, $active_id, $pass);
					$subFolder = $this->createCommentFile(
						$zip,
						$userdata,
						$questionBase,
						$objQuestion,
						$active_id,
						$pass,
						null
					);

					$zip->addFromString($subFolder . '/cloze.json', $res['input'] . '');
					$zip->addFromString($subFolder . '/answer.json', $res['sol'] . '');
				} else if (method_exists($objQuestion, 'getOrderingElements')) {
					$json = $this->jsonFromHorizOrderingQuestion($objQuestion, $active_id, $pass);
					$subFolder = $this->createCommentFile(
						$zip,
						$userdata,
						$questionBase,
						$objQuestion,
						$active_id,
						$pass,
						null
					);

					$zip->addFromString($subFolder . '/order.json', $json . '');
				} else if (method_exists($objQuestion, 'getOrderingElementList')) {
					$json = $this->jsonFromOrderingQuestion($objQuestion, $active_id, $pass);

					$subFolder = $this->createCommentFile(
						$zip,
						$userdata,
						$questionBase,
						$objQuestion,
						$active_id,
						$pass,
						null
					);

					$zip->addFromString($subFolder . '/order.json', $json . '');
				} else if (
					method_exists($objQuestion, 'getCompleteSource') &&
					method_exists($objQuestion, 'getExportFilename') &&
					method_exists($objQuestion, 'getExportSolution')
				) {

					$base_filename = $autoFileName ? $objQuestion->getExportFilename(NULL) : ("Solution." . $objQuestion->getExportExtension());

					$solution = $objQuestion->getExportSolution($active_id, $pass);
					$osolution = $solution;

					//ignore invalid solution
					if ($solution == null)
						continue;

					if ($ignoreEmpty) {
						$rerun = true;
						while ($pass > 0 && $rerun) {
							$studentCode = trim($this->justAnswers($objQuestion, $solution, true));
							$emptyCode = trim($this->justAnswers($objQuestion, NULL, true));
							//echo ":".$studentCode." ".$pass.":<br>:".$emptyCode.":<br>";                                
							if (($studentCode == $emptyCode || $studentCode == '') && $pass > 0) {
								$pass--;
								$solution = $objQuestion->getExportSolution($active_id, $pass);
								if ($solution == null) {
									$solution = $osolution;
								}
							} else {
								$rerun = false;
							}
						}
					}

					$filename = $autoFileName ? $objQuestion->getExportFilename($solution) : ("Solution." . $objQuestion->getExportExtension());
					// echo $filename ." - ".$opass . " - " .$pass;
					// die;					

					if (!isset($solution["solution_id"]))
						continue;

					$code = CodeBlock::fixExportedCode($objQuestion->getCompleteSource($solution));
					$blocks = $objQuestion->blocks()->getCombinedBlocks($solution['value2'], true, $solution['value1']);

					$subFolder = $this->createCommentFile(
						$zip,
						$userdata,
						$questionBase,
						$objQuestion,
						$active_id,
						$pass,
						$solution
					);

					$zip->addFromString($subFolder . '/' . $filename, $code);

					//we have the randomizer, so dump its values
					if ($objQuestion->blocks()->getRandomizerActive()) {
						$set = $this->getRandomSet($objQuestion, $active_id, $pass);
						if ($set != NULL) {
							$zip->addFromString($subFolder . '/randomizer.json', json_encode($set));
						}
					}

					//dump a solution html rendering for the VSCode Extension 
					{
						$solutions = $objQuestion->getSolutionValuesOrInit($active_id, $pass, true, false, false);
						$html = $objQuestion->blocks()->ui()->render(false, false, true, $solution['value1'], $solution['value2']);
						$zip->addFromString($subFolder . '/rendered.html', $html);
					}

					//add the question-text and other meta info to download 
					{
						$info = ['title' => $objQuestion->getTitle(), 'hint' => $objQuestion->getComment(), 'description' => $objQuestion->getQuestion()];
						$zip->addFromString($subFolder . '/meta.json', json_encode($info));
					}

					//generate files for each block
					for ($i = 0; $i < count($blocks); $i++) {
						$t = $objQuestion->blocks()[$i]->getType();
						if ($t == assCodeQuestionBlockTypes::SolutionCode) {
							$zip->addFromString($subFolder . '/' . $i . '.solution.' . $base_filename, CodeBlock::fixExportedCode($blocks[$i]));
						} else if ($t == assCodeQuestionBlockTypes::StaticCode) {
							$zip->addFromString($subFolder . '/' . $i . '.static.' . $base_filename, CodeBlock::fixExportedCode($blocks[$i]));
						} else if ($t == assCodeQuestionBlockTypes::HiddenCode) {
							$zip->addFromString($subFolder . '/' . $i . '.hidden.' . $base_filename, CodeBlock::fixExportedCode($blocks[$i]));
						}
					}
				}
			}
			// Access some user related properties
			//$last_visited = $data->getParticipant($active_id)->getLastVisit();
		}

		$zip->close();

		return NULL;
	}

	protected function getNumericValueFromText($text)
	{
		include_once("./Services/Math/classes/class.EvalMath.php");
		$eval = new EvalMath();
		$eval->suppress_errors = true;
		return $eval->e(str_replace(",", ".", ilUtil::stripSlashes($text, false)));
	}
	protected function jsonFromClozeQuestion($objQuestion, $active_id, $pass)
	{
		$input = [];
		$input['gaps'] = [];
		for ($i = 0; $i < $objQuestion->getGapCount(); $i++) {
			$data = [];

			$gap = $objQuestion->getGap($i);
			//assAnswerCloze

			$data['type'] = $gap->getType();
			$data['shuffle'] = $gap->getShuffle();
			$data['items'] = [];
			$data['id'] = $i + 1;
			foreach ($gap->getItemsRaw() as $nr => $item) {
				$d = [];
				$d['order'] = 0 + $item->getOrder();
				$d['points'] = 0 + $item->getPoints();
				$d['size'] = $item->getGapSize();
				if ($data['type'] == 2) {
					$d["lower"] = 0 + $item->getLowerBound();
					$d["upper"] = 0 + $item->getUpperBound();
					$d["value"] = 0 + $this->getNumericValueFromText($item->getAnswertext());
				} else {

					$d['text'] = $item->getAnswertext();
				}

				$data['items'][] = $d;
			}
			$input['gaps'][] = $data;
		}
		$input["text"] = $objQuestion->getClozeText();

		$results = [];
		$solution = $objQuestion->getUserQuestionResult($active_id, $pass);
		foreach ($solution->getSolutions() as $key => $value) {
			$d = [];
			$d['id'] = $value["key"];
			$d['value'] = $value["value"];
			$results[] = $d;
		}

		return ["input" => json_encode($input), "sol" => json_encode($results)];
	}

	protected function jsonFromHorizOrderingQuestion($objQuestion, $active_id, $pass)
	{
		$soll = $objQuestion->getOrderingElements();
		$ist = array();

		$solution = $objQuestion->getSolutionValues($active_id, $pass);
		if (count($solution) > 0) {
			if (strlen($solution[0]["value1"])) {
				$ist = explode("{::}", $solution[0]["value1"]);
			}
		}

		$mapSoll = array();
		$mapIst = array();
		foreach ($soll as $k => $v) {
			$mapSoll[$k] = $v;
		}
		foreach ($ist as $k => $v) {
			$mapIst[$k] = $v;
		}

		$map = array('solution' => $mapSoll, 'student' => $mapIst);
		$map['max_points'] = $objQuestion->getPoints();
		return json_encode($map);
	}

	protected function jsonFromOrderingQuestion($objQuestion, $active_id, $pass)
	{
		$soll = $objQuestion->getOrderingElementList();
		$ist = array();

		$indexedSolutionValues = $objQuestion->fetchIndexedValuesFromValuePairs(
			$objQuestion->getTestOutputSolutions($active_id, $pass)
		);
		if (count($indexedSolutionValues)) {
			$ist = $objQuestion->getSolutionOrderingElementList($indexedSolutionValues);
		}


		$mapSoll = array();
		$mapIst = array();
		foreach ($soll as $k => $v) {
			$mapSoll[$k] = $v->getContent();
		}
		foreach ($ist as $k => $v) {
			$mapIst[$k] = $v->getContent();
		}

		$map = array('solution' => $mapSoll, 'student' => $mapIst);
		$map['max_points'] = $objQuestion->getPoints();
		return json_encode($map);
	}

	protected function createCommentFile($zip, $userdata, $questionBase, $objQuestion, $active_id, $pass, $solution = null)
	{
		if ($solution == null) {
			$solution = $objQuestion->getSolutionValues($active_id, $pass);
			if (count($solution) > 0)
				$solution = $solution[count($solution) - 1];
		}
		if (!isset($solution) || !isset($solution['solution_id'])) {
			return sprintf("empty-%06d-%06d-%06d-%s", $active_id, $pass, $userdata->user_id, $userdata->login);
		}

		// print("createCommentFile<br>");
		// print("active_id:" . $active_id . "<br>");
		// print("pass:" . $pass . "<br>");
		// print_r($solution);
		// print('solution_id: ' . $solution['solution_id'] . '<br>');
		// print('active_fi: ' . $solution['active_fi'] . '<br>');
		// print('pass: ' . $solution['pass'] . '<br>');
		// print('user_id: ' . $userdata->user_id . '<br>');
		// print('login: ' . $userdata->login . '<br>');
		$subFolder = sprintf("solution-%06d-%06d-%06d-%06d-%s", $solution['solution_id'], $solution['active_fi'], $solution['pass'], $userdata->user_id, $userdata->login);
		$subFolder = $questionBase . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '', $subFolder);

		$feedback = $this->testObj->getManualFeedback(
			$solution['active_fi'],
			$solution['question_fi'],
			$solution["pass"]
		);
		$points = $this->getReachedPoints(
			$solution['active_fi'],
			$solution['question_fi'],
			$solution["pass"]
		);

		$comment = "POINTS: %3.1f\n%s\n";
		$comment = sprintf($comment, $points, $feedback);
		$zip->addFromString($subFolder . '/comment_', $comment);

		return $subFolder;
	}

	protected function getReachedPoints($active_fi, $question_fi, $pass)
	{
		global $ilDB;

		$query = "SELECT * FROM tst_test_result WHERE " .
			'active_fi=' . $ilDB->quote($active_fi, 'integer') . " AND " .
			'question_fi=' . $ilDB->quote($question_fi, 'integer') . " AND " .
			'pass=' . $ilDB->quote($pass, 'integer') .
			" ORDER BY active_fi";

		$result = $ilDB->query($query);


		while ($row = $ilDB->fetchAssoc($result)) {
			return $row['points'];
		}

		return 0;
	}

	protected function buildCode($objQuestion, $solution)
	{
		$blocks = $objQuestion->blocks()->getCombinedBlocks($solution['value2'], true, $solution['value1']);

		$res = '';
		$justCode = '';
		$line = 1;
		for ($i = 0; $i < count($blocks); $i++) {
			$t = $objQuestion->blocks()[$i]->getType();
			if ($t == assCodeQuestionBlockTypes::SolutionCode) {
				$res .= '\\begin{lstlisting}[firstnumber=' . $line . ', frame=single]' . "\n";
				if (!empty($studentCode)) {
					$res .= $studentCode->$i . "\n";
					$justCode .= $studentCode->$i . "\n";
				}
				$res .= '\\end{lstlisting}' . "\n";
			} else if ($t == assCodeQuestionBlockTypes::StaticCode || $t == assCodeQuestionBlockTypes::HiddenCode) {
				$res .= '\\begin{lstlisting}[firstnumber=' . $line . ', basicstyle=\\linespread{0.8}\\sffamily]' . "\n";
				$res .= $blocks[$i] . "\n";
				$justCode .= $blocks[$i] . "\n";
				$res .= '\\end{lstlisting}' . "\n";
			}

			$line = count(explode("\n", $justCode));
		}
		return $res;
	}

	protected function initParticipantString($participant, $test)
	{
		$string = sprintf("\\documentclass[landscape]{article}\n\n" .
			"\\usepackage[margin=2cm,right=3cm]{geometry}\n" .
			"\\usepackage[ngerman]{babel}\n" .
			"\\usepackage[doublespacing]{setspace}\n" .
			"\usepackage{lastpage}" .
			"\\usepackage{listings}\n" .
			"\\usepackage{color}\n\n" .
			"\\definecolor{light-gray}{gray}{0.85}\n" .
			"\\makeatletter\n" .
			"\\def\\verbatim@font{\\linespread{1}\\normalfont\\ttfamily}\n" .
			"\\makeatother\n\n" .
			"\\lstset{ \n" .
			"\t language=Java,\n" .
			"\t breakatwhitespace=false,\n" .
			"\t breaklines=true,\n" .
			"\t captionpos=b,\n" .
			"\t keepspaces=true,\n" .
			"\t numbers=left, \n" .
			"\t showspaces=false,\n" .
			"\t showstringspaces=true,\n" .
			"\t showtabs=false,\n" .
			"\t tabsize=4,\n" .
			"\t showlines=true,\n" .
			"\t basicstyle=\\linespread{1.5}\\ttfamily,\n" .
			"\t keywordstyle=\\color{blue}\\ttfamily,\n" .
			"\t stringstyle=\\color{red}\\ttfamily,\n" .
			"\t commentstyle=\\color{light-gray}\\ttfamily,\n" .
			"\t morecomment=[l][\\color{light-gray}]\n" .
			"}\n\n" .
			"\\usepackage{fancyhdr}\n" .
			"\\pagestyle{fancy}\n" .
			"\\lhead{%s}\n" .
			"\\lfoot{Klausur Grundlagen der Informatik (%s)}\n" .
			"\\rfoot{(Seite \\thepage /\\pageref{LastPage})}\n" .
			"\\cfoot{}" .
			"\\renewcommand{\\headrulewidth}{0.4pt}\n" .
			"\\renewcommand{\\footrulewidth}{0.4pt}\n\n" .
			"\\begin{document}\n", $participant, $test);
		return $string;
	}

	protected function addQuestionToString($string, $question, $code)
	{
		$string = $string . sprintf(
			"\\rhead{%s}\n" .
			"%s \n" .
			"\\newpage \n\n",
			$question,
			$code
		);
		return $string;
	}

	protected function addTestResultToString($string, $solution)
	{
		$feedback = $this->testObj->getManualFeedback(
			$solution['active_fi'],
			$solution['question_fi'],
			$solution["pass"]
		);
		$feedback = str_replace('<pre style="font-family:monospace">', '', $feedback);
		$feedback = str_replace('</pre>', '', $feedback);
		$points = $this->getReachedPoints(
			$solution['active_fi'],
			$solution['question_fi'],
			$solution["pass"]
		);

		$comment = "POINTS: %3.1f\n%s\n";
		$comment = sprintf($comment, $points, $feedback);
		$string = $string . '\\begin{verbatim}' . $comment . '\\end{verbatim}' .
			"\\newpage \n\n";
		return $string;
	}

	protected function finishParticipantString($string)
	{
		$string = $string . sprintf(
			"\\end{document}"
		);
		return $string;
	}

	protected function getParticipantInfo($active_fi)
	{
		global $ilDB;

		$query = "SELECT lastname, firstname, login, matriculation " .
			"FROM tst_active, usr_data " .
			"WHERE user_fi = usr_id " .
			"AND active_id = " . $ilDB->quote($active_fi, 'integer');

		$result = $ilDB->query($query);

		while ($row = $ilDB->fetchAssoc($result)) {
			return $row['lastname'] . '-' . $row['firstname'] . '-' . $row['login'] . '-' . $row['matriculation'];
		}

		return 0;
	}
}

?>