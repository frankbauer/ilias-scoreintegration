<?php

include_once("./Services/UIComponent/classes/class.ilUserInterfaceHookPlugin.php");
 
/**
 * User interface plugin
 *
 * @author Frank Bauer <frank.bauer@fau.de>
 * @version $Id$
 *
 */
class ilCodeQuestionScoreIntegrationPlugin extends ilUserInterfaceHookPlugin {
	function getPluginName():string {
		return "CodeQuestionScoreIntegration";
	}
}

?>
