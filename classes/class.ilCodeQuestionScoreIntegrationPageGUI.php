<?php
require_once ('Modules/Test/classes/class.ilObjTest.php');
require_once 'Services/Form/classes/class.ilPropertyFormGUI.php';
/**
 * Extended Test Statistic Page GUI
 *
 * @author Frank Bauer <frank.bauer@fau.de>
 * @version $Id$
 *
 * @ilCtrl_IsCalledBy ilCodeQuestionScoreIntegrationPageGUI: ilUIPluginRouterGUI
 */
class ilCodeQuestionScoreIntegrationPageGUI
{
    /** @var ilCtrl $ctrl */
	protected $ctrl;

	/** @var ilTemplate $tpl */
	protected $tpl;

	/** @var ilCodeQuestionScoreIntegrationPlugin $plugin */
	protected ilCodeQuestionScoreIntegrationPlugin  $plugin;

	/** @var ilObjTest $testObj */
	protected $testObj;

	/** @var ilCodeQuestionScoreIntegration $estObj */
	protected $estObj;

	/**
	 * ilCodeQuestionScoreIntegrationPageGUI constructor.
	 */
	public function __construct()
	{
		global $ilCtrl, $tpl, $lng;

		$this->ctrl = $ilCtrl;
		$this->tpl = $tpl;

		$lng->loadLanguageModule('assessment');

		$this->plugin = ilCodeQuestionScoreIntegration::initPluginObject('CodeQuestionScoreIntegration');
		$this->plugin->loadLanguageModule();

		$this->testObj = new ilObjTest($_GET['ref_id']);
		$this->estObj = new ilCodeQuestionScoreIntegration($this->testObj, $this->plugin);
	}

	private function redirectToIndex(){
		ilUtil::redirect('ilias.php?baseClass=iluipluginroutergui&cmdNode=we:xh&cmdClass=ilCodeQuestionScoreintegrationpagegui&cmd=showMainAutoScorePage&ref_id='.$this->testObj->getRefId());
	}

	/**
	* Handles all commands, default is "show"
	*/
	public function executeCommand()
	{
		
		/** @var ilAccessHandler $ilAccess */
		/** @var ilErrorHandling $ilErr */
		global $ilAccess, $ilErr, $lng;

		if (!$ilAccess->checkAccess('write','',$this->testObj->getRefId()))
		{
			ilUtil::sendFailure($lng->txt("permission_denied"), true);
            $this->redirectToIndex();
		}
		$cmd = $this->ctrl->getCmd('showMainAutoScorePage');
		
		switch ($cmd)
		{
			case 'uploadFiles':			
				$this->prepareOutput();
				$this->tpl->setContent($this->uploadFiles());
                $this->tpl->printToStdout();
				break;
			case 'showMainAutoScorePage':
				$this->prepareOutput();
				$this->tpl->setContent($this->overviewContent());
                $this->tpl->printToStdout();
			break;
			case 'zip':
				$this->sendZIP(FALSE);
			break;
			case 'latexZip':
				$this->sendZIP(TRUE);
			break;
			default:
                ilUtil::sendFailure($lng->txt("permission_denied"), true);
                $this->redirectToIndex();
				break;
		}
	}

	protected function getMaxFileSizeString()
	{
		// get the value for the maximal uploadable filesize from the php.ini (if available)
		$umf = ini_get("upload_max_filesize");
		// get the value for the maximal post data from the php.ini (if available)
		$pms = ini_get("post_max_size");
		
		//convert from short-string representation to "real" bytes
		$multiplier_a=array("K"=>1024, "M"=>1024*1024, "G"=>1024*1024*1024);
		
		$umf_parts=preg_split("/(\d+)([K|G|M])/", $umf, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
        $pms_parts=preg_split("/(\d+)([K|G|M])/", $pms, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
        
        if (count($umf_parts) == 2) { $umf = $umf_parts[0]*$multiplier_a[$umf_parts[1]]; }
        if (count($pms_parts) == 2) { $pms = $pms_parts[0]*$multiplier_a[$pms_parts[1]]; }
        
        // use the smaller one as limit
		$max_filesize = min($umf, $pms);

		if (!$max_filesize) $max_filesize=max($umf, $pms);
	
    	//format for display in mega-bytes
		$max_filesize = sprintf("%.1f MB",$max_filesize/1024/1024);
		
		return $max_filesize;
	}

	/**
	 * Prepares Fileupload form and returns it.
	 * @return ilPropertyFormGUI
	 */
	public function getFileUploadForm()
	{
		/**
		 * @var $lng ilLanguage
		 */
		global $lng, $ilCtrl;

		$form = new ilPropertyFormGUI();
		$form->setId("upload");
        $form->setMultipart(true);
		$form->setHideLabels();
		//$form->setTarget("cld_blank_target");
		$form->setFormAction($ilCtrl->getFormAction($this, "uploadFiles"));
		$form->setTableWidth("100%");

		$item = new ilCustomInputGUI($this->plugin->txt('archive_file'));		
		$item->setHTML('<input type="file" id="upload_files" name="upload_files">');
		$form->addItem($item);	

        $set_scored = new ilCheckboxInputGUI($lng->txt('set_manscoring_done'), 'set_manscoring_done');
        $set_scored->setValue('set');
        $set_scored->setChecked(true);
        $form->addItem($set_scored);

        $passOverride = new ilCheckboxInputGUI($this->plugin->txt('pass_override'),'pass_override');		
		$passOverride->setRequired(false);

		$passOverride->setValue('ov');
		$passOverride->setChecked(false);
		$form->addItem($passOverride);

		$form->addCommandButton('uploadFiles', $lng->txt('submit'));
		return $form;
	}

	private function uploadCodeToMessage($code) 
    { 
        switch ($code) { 
            case UPLOAD_ERR_INI_SIZE: 
                $message = "The uploaded file exceeds the upload_max_filesize directive in php.ini"; 
                break; 
            case UPLOAD_ERR_FORM_SIZE: 
                $message = "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form";
                break; 
            case UPLOAD_ERR_PARTIAL: 
                $message = "The uploaded file was only partially uploaded"; 
                break; 
            case UPLOAD_ERR_NO_FILE: 
                $message = "No file was uploaded"; 
                break; 
            case UPLOAD_ERR_NO_TMP_DIR: 
                $message = "Missing a temporary folder"; 
                break; 
            case UPLOAD_ERR_CANT_WRITE: 
                $message = "Failed to write file to disk"; 
                break; 
            case UPLOAD_ERR_EXTENSION: 
                $message = "File upload stopped by extension"; 
                break; 

            default: 
                $message = "Unknown upload error"; 
                break; 
        } 
        return $message; 
    } 

	/**
	 * Prepares Fileupload form and returns it.
	 * @return ilPropertyFormGUI
	 */
	public function getDragAndDropFileUploadForm()
	{
		/**
		 * @var $lng ilLanguage
		 */
		global $lng, $ilCtrl;
		include_once("./Services/Form/classes/class.ilDragDropFileInputGUI.php");
        include_once("./Services/jQuery/classes/class.iljQueryUtil.php");
		$form = new ilPropertyFormGUI();
		$form->setId("upload");
        $form->setMultipart(true);
		$form->setHideLabels();
		$form->setTarget("cld_blank_target");
		$form->setFormAction($ilCtrl->getFormAction($this, "uploadFiles"));
		$form->setTableWidth("100%");
		
		$file_input = new ilDragDropFileInputGUI($lng->txt("cld_upload_files"), "upload_files");
		$file_input->setPostVar('file_to_upload');		
		$file_input->setTitle($lng->txt('upload'));
		$file_input->setSuffixes(array( ".zip" ));		
		
		$form->addItem($file_input);
		$form->addCommandButton("uploadFiles", $lng->txt("upload"));
        $form->addCommandButton("cancelAll", $lng->txt("cancel"));
		
		
		return $form;
	}

	protected function uploadFiles(){
		/*$form = $this->getDragAndDropFileUploadForm();			
		if ($form->checkInput()){}*/
		$tpl = $this->overviewTemplate();
		$file = $_FILES['upload_files'];

		if ($file['error']!=0){
			ilUtil::sendFailure($this->uploadCodeToMessage($file['error']), true);
			$this->redirectToIndex();
			return;
		}

		if (!file_exists($file['tmp_name'])){
			ilUtil::sendFailure($lng->txt('file_not_found'), true);
			$this->redirectToIndex();
			return;
		}
		$results = $this->estObj->processZipFile($file['tmp_name']);

		$info_tpl = $this->plugin->getTemplate('tpl.il_ui_uihk_uicodequestionscore_succes_info.html');
		$err_tpl = $this->plugin->getTemplate('tpl.il_ui_uihk_uicodequestionscore_fail_info.html');

		$err_tpl->setVariable('LBL_LOGIN', $this->plugin->txt('label_login'));
		$err_tpl->setVariable('LBL_REASON', $this->plugin->txt('label_reason'));
		$err_tpl->setVariable('LBL_POINTS', $this->plugin->txt('label_points'));
		$err_tpl->setVariable('LBL_COMMENT', $this->plugin->txt('label_comment'));
		$err_tpl->setVariable('LBL_CONTENT', $this->plugin->txt('label_content'));
		$err_tpl->setVariable('LBL_TESTID', $this->plugin->txt('label_testid'));
		$err_tpl->setVariable('H_INVALID', $this->plugin->txt('h_invalid_comment_file'));
		$err_tpl->setVariable('H_OTHER_TEST', $this->plugin->txt('h_wrong_test'));
		$err_tpl->setVariable('H_UNPROCESSED', $this->plugin->txt('h_unprocessed'));
		$err_tpl->setVariable('H_STORED', $this->plugin->txt('h_stored'));


		//SUCCESS		
		$info_tpl->setCurrentBlock("success_line");
		$count = 0;
		$countu = 0;
		foreach($results["files"] as $file){
			if ($file['stored']){
				$count++;
				
				$info_tpl->setVariable("LOGIN", $file['login']);
				$info_tpl->setVariable("POINTS", $file['points']);
				$info_tpl->setVariable("COMMENT", $file['comment']);
				$info_tpl->setVariable('LBL_LOGIN', $this->plugin->txt('label_login'));
				$info_tpl->setVariable('LBL_POINTS', $this->plugin->txt('label_points'));
				$info_tpl->setVariable('LBL_COMMENT', $this->plugin->txt('label_comment'));
				$info_tpl->setVariable('H_UNPROCESSED', $this->plugin->txt('h_unprocessed'));
				
				$info_tpl->parseCurrentBlock();
			} else {
				$countu++;
			}
		}
		$info_tpl->setVariable("NUMBER_STORED", $count);

		//ERROR
		$error_count = $countu + count($results['wrongTest']) + count($results['invalidComment']);
		if ($countu>0){
			$err_tpl->setCurrentBlock("unprocessed_line");
			foreach($results["files"] as $file){
				if (!$file['stored']){
					$err_tpl->setVariable("LOGIN", $file['login']);
					$err_tpl->setVariable("POINTS", $file['points']);
					$err_tpl->setVariable("COMMENT", $file['comment']);
					$err_tpl->setVariable("ERROR", $file['error']);
					$err_tpl->parseCurrentBlock();
				}
			}
			$err_tpl->setVariable("NUMBER_UNPROCESSED", $countu);

			$err_tpl->setCurrentBlock("unprocessed");
			$err_tpl->parseCurrentBlock();
		}
				
		
		if (count($results['wrongTest'])>0){
			$err_tpl->setCurrentBlock("wrongtest_line");
			foreach($results["wrongTest"] as $file){
				$err_tpl->setVariable("LOGIN", $file['login']);
				$err_tpl->setVariable("TEST_ID", $file['testID']);
				$err_tpl->parseCurrentBlock();				
			}
			$err_tpl->setVariable("NUMBER_WRONG", count($results['wrongTest']));

			$err_tpl->setCurrentBlock("wrongtest");
			$err_tpl->parseCurrentBlock();
		}

		if (count($results['invalidComment'])>0){
			$err_tpl->setCurrentBlock("invalid_line");
			foreach($results["invalidComment"] as $file){
				$err_tpl->setVariable("LOGIN", $file['login']);
				$err_tpl->setVariable("CONTENT", $file['rawContent']);
				$err_tpl->parseCurrentBlock();				
			}
			$err_tpl->setVariable("NUMBER_INVALID", count($results['invalidComment']));

			$err_tpl->setCurrentBlock("invalid");
			$err_tpl->parseCurrentBlock();
		}
		
		if ($error_count > 0){
			$this->tpl->setOnScreenMessage('failure', 'There were ' . $error_count . ' Problems while importing!');
			$this->tpl->setOnScreenMessage('failure', $err_tpl->get());
		} else {
			$this->tpl->setOnScreenMessage('success', 'All records were imported.');
			$this->tpl->setOnScreenMessage('info', $info_tpl->get());
		}

		/*
		ilUtil::sendSuccess($info_tpl->get(), true);
		ilUtil::sendFailure($err_tpl->get(), true);
		*/
		return $tpl->get();
	}

	protected function overviewTemplate(){
		global $ilCtrl, $ilDB, $lng;

		$data      = $this->testObj->getCompleteEvaluationData(TRUE);
		$ilCtrl->saveParameterByClass('ilCodeQuestionScoreIntegrationPageGUI','ref_id');

		$tpl = $this->plugin->getTemplate('tpl.il_ui_uihk_uicodequestionscore_main_page.html');
		$tpl->setVariable("PARTICIPANT_COUNT", count($data->getParticipants()));
		$tpl->setVariable("LINK_ZIP", $ilCtrl->getLinkTargetByClass(array('ilUIPluginRouterGUI','ilCodeQuestionScoreIntegrationPageGUI')).'&cmd=zip');
		$tpl->setVariable("LINK_LATEXZIP", $ilCtrl->getLinkTargetByClass(array('ilUIPluginRouterGUI','ilCodeQuestionScoreIntegrationPageGUI')).'&cmd=latexZip');
		//echo $this->getFileUploadFormHTML()."<hr>";die;
		//$upload = $this->getFileUploadForm();
		$tpl->setVariable("FILE_UPLOAD", $this->getFileUploadForm()->getHTML());

		$tpl->setVariable('TITLE', $this->plugin->txt('title'));
		$tpl->setVariable('P_COUNT', $this->plugin->txt('h_nr_participants'));
		$tpl->setVariable('H_ARCH', $this->plugin->txt('h_solution_archive'));
		$tpl->setVariable('TXT_ARCH', $this->plugin->txt('html_solution_archive'));
		$tpl->setVariable('DNL_ARCH', $this->plugin->txt('lnk_solution_archive'));
		$tpl->setVariable('H_TEX', $this->plugin->txt('h_tex'));
		$tpl->setVariable('TXT_TEX', $this->plugin->txt('html_tex'));
		$tpl->setVariable('DNL_TEX', $this->plugin->txt('lnk_tex'));
		$tpl->setVariable('H_UPLOAD', $this->plugin->txt('h_upload'));
		$tpl->setVariable('TXT_UPLOAD', $this->plugin->txt('html_upload'));
        $tpl->setVariable('IGNORE_EMPTY_TEXT', $this->plugin->txt('ignore_empty'));
        $tpl->setVariable('USE_AUTO_FILE', $this->plugin->txt('use_auto_file'));
		return $tpl;
	}

	protected function overviewContent(){		
		
		return $this->overviewTemplate()->get();					
	}

	/**
	 * Prepare the test header, tabs etc.
	 */
	protected function prepareOutput()
	{
		/** @var ilLocatorGUI $ilLocator */
		/** @var ilLanguage $lng */
		global $ilLocator, $lng;

		$this->ctrl->setParameterByClass('ilObjTestGUI', 'ref_id',  $this->testObj->getRefId());
		$ilLocator->addRepositoryItems($this->testObj->getRefId());
		$ilLocator->addItem($this->testObj->getTitle(),$this->ctrl->getLinkTargetByClass('ilObjTestGUI'));

		//$this->tpl->getStandardTemplate();
		$this->tpl->setLocator();
		$this->tpl->setTitle($this->testObj->getPresentationTitle());
		$this->tpl->setDescription($this->testObj->getLongDescription());
		$this->tpl->setTitleIcon(ilObject::_getIcon(0, 'big', 'tst'), $lng->txt('obj_tst'));
		$this->tpl->addCss($this->plugin->getStyleSheetLocation('uicodequestionscore.css'));		

		return true;
	}

	function sendZIP($latex){
		/** @var ilAccessHandler $ilAccess */
		/** @var ilErrorHandling $ilErr */
		global $ilAccess, $ilErr, $lng;

		if (!$ilAccess->checkAccess('write','',$this->testObj->getRefId()))
		{
			ilUtil::sendFailure($lng->txt("permission_denied"), true);
            $this->redirectToIndex();
		}

		if ($latex) {
			$zipFile  = tempnam(sys_get_temp_dir(), 'Latex_').".zip";
			$err = $this->estObj->buildLatexZIP($zipFile);
		} else {
			$zipFile  = tempnam(sys_get_temp_dir(), 'EST_').".zip";
			$err = $this->estObj->buildZIP($zipFile);
		}

		if (!is_null($err))
		{
			ilUtil::sendFailure($err, true);
            $this->redirectToIndex();
		}

		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		header('Content-Type: ' . finfo_file($finfo, $zipFile));
		finfo_close($finfo);

		//Use Content-Disposition: attachment to specify the filename
		header('Content-Disposition: attachment; filename='.basename($zipFile));

		//No cache
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');

		//Define file size
		header('Content-Length: ' . filesize($zipFile));

		ob_clean();
		flush();
		readfile($zipFile);

		//cleanup
		if (file_exists($zipFile)){
			unlink($zipFile);
		}
		ilUtil::sendSuccess($this->plugin->txt("download_created"), true);		
		$this->redirectToIndex();
		//die;		
	}
}
?>