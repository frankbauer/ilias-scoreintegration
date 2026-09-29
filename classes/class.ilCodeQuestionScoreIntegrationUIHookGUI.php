<?php

/**
 * User interface hook class
 *
 * @author Frank Bauer <frank.bauer@fau.de>
 * @version $Id$
 * @ingroup ServicesUIComponent
 */
class ilCodeQuestionScoreIntegrationUIHookGUI extends ilUIHookPluginGUI {

	/**
	 * @var bool true if the test includes source code questions
	 */
	var $hasCodeQuestions = false;


	public function __construct() {

	}

	/**
	 * Modify HTML output of GUI elements. Modifications modes are:
	 * - ilUIHookPluginGUI::KEEP (No modification)
	 * - ilUIHookPluginGUI::REPLACE (Replace default HTML with your HTML)
	 * - ilUIHookPluginGUI::APPEND (Append your HTML to the default HTML)
	 * - ilUIHookPluginGUI::PREPEND (Prepend your HTML to the default HTML)
	 *
	 * @param string $a_comp component
	 * @param string $a_part string that identifies the part of the UI that is handled
	 * @param string $a_par array of parameters (depend on $a_comp and $a_part)
	 *
	 * @return array array with entries "mode" => modification mode, "html" => your html
	 */
	function getHTML(string $a_comp, string $a_part, array $a_par = []): array {
		return array("mode" => ilUIHookPluginGUI::KEEP, "html" => "");
	}

	/**
	 * Is the manual scoring of a test shown? ILIAS >= 10 renders it in ConsecutiveScoringGUI, which
	 * activates the "manscoring" tab (ilCtrl still reports ilObjTestGUI as command class there).
	 * The class names are the manual scoring pages of ILIAS <= 9.
	 */
	private function isManualScoringPage(?string $cmdClass, ilTabsGUI $tabs): bool {
		if ($cmdClass == 'ilcodequestionscoreintegrationpagegui') {
			return false;
		}
		return $tabs->getActiveTab() == \ILIAS\Test\Presentation\TabsManager::TAB_ID_MANUAL_SCORING
			|| in_array($cmdClass, array(
				strtolower(\ILIAS\Test\Scoring\Manual\ConsecutiveScoringGUI::class),
				'iltestscoringbyquestionsgui',
				'iltestscoringgui'
			));
	}

	function numberOfCodeQuestions() {
		global $DIC;
		$ilDB = $DIC->database();
		$refId = (int) ($DIC->http()->request()->getQueryParams()['ref_id'] ?? 0);
		$oID = (int) ilObject::_lookupObjId($refId);
		$count = 0;

		$query = "SELECT count(q.question_id) AS 'nr' FROM qpl_questions AS q LEFT JOIN qpl_qst_type AS t ON q.question_type_fi = t.question_type_id WHERE (t.type_tag = 'assCodeQuestion' OR t.type_tag = 'assOrderingHorizontal' OR t.type_tag = 'assOrderingQuestion')AND obj_fi = $oID";
		$result = $ilDB->query($query);
		while ($row = $ilDB->fetchAssoc($result)) {
			$count = $row['nr'] + 0;
		}

		//echo "r=".$_GET['ref_id'].", 0=$oID, count=$count<br>$query";die;
		return $count;
	}

	/**
	 * Modify GUI objects, before they generate ouput
	 *
	 * @param string $a_comp component
	 * @param string $a_part string that identifies the part of the UI that is handled
	 * @param string $a_par array of parameters (depend on $a_comp and $a_part)
	 */
	function modifyGUI(string $a_comp, string $a_part, array $a_par = []): void {
		global $DIC;

		switch ($a_part) {
			// case 'tabs':
			case 'sub_tabs':
				// only resolve the services here: modifyGUI is also called during init (e.g. init_style)
				$ilCtrl = $DIC->ctrl();
				$ilTabs = $a_par['tabs'] ?? $DIC->tabs();
				if ($this->isManualScoringPage($ilCtrl->getCmdClass(), $ilTabs)) {
					if (!$this->hasCodeQuestions) {
						$this->hasCodeQuestions = $this->numberOfCodeQuestions() > 0;
					}
					if (!$this->hasCodeQuestions)
						return;

					$ilCtrl->saveParameterByClass('ilCodeQuestionScoreIntegrationPageGUI', 'ref_id');

					// ILIAS >= 10 shows the manual scoring without sub tabs, and a single sub tab is not
					// rendered at all: add one for the manual scoring itself (as in ILIAS 9)
					if (count($ilTabs->sub_target) == 0) {
						foreach ($ilTabs->target as $target) {
							if ($target['id'] == \ILIAS\Test\Presentation\TabsManager::TAB_ID_MANUAL_SCORING) {
								$ilTabs->addSubTab($target['id'], $DIC->language()->txt('manscoring'), $target['link']);
								$ilTabs->activateSubTab($target['id']);
							}
						}
					}

					$ilTabs->addSubTab(
						"scrintegration",
						$this->plugin_object->txt("score_integration"),
						$ilCtrl->getLinkTargetByClass(array('ilUIPluginRouterGUI', 'ilCodeQuestionScoreIntegrationPageGUI'))
					);

					// save the tabs for reuse on the plugin pages
					// (these do not have the test gui as parent)
					// not nice, but effective
					ilSession::set('CodeQuestionScoreIntegration', array(
						'TabTarget' => $ilTabs->target,
						'TabSubTarget' => $ilTabs->sub_target
					));
				}

				if ($ilCtrl->getCmdClass() == 'ilcodequestionscoreintegrationpagegui') {
					// reuse the tabs that were saved from the test gui
					$saved = ilSession::get('CodeQuestionScoreIntegration');
					if (is_array($saved) && isset($saved['TabTarget'])) {
						$ilTabs->target = $saved['TabTarget'];
					}
					if (is_array($saved) && isset($saved['TabSubTarget'])) {
						$ilTabs->sub_target = $saved['TabSubTarget'];
					}

					// this works because the tabs are rendered after the sub tabs
					$ilTabs->activateTab('manscoring');
					$ilTabs->activateSubTab('scrintegration');
				}

				break;

			default:
				break;
		}
	}

}
?>