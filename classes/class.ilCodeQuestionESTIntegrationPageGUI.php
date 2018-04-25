<?php
require_once ('Modules/Test/classes/class.ilObjTest.php');
require_once('./Services/FileUpload/classes/class.ilFileUploadGUI.php');
require_once 'Services/Form/classes/class.ilPropertyFormGUI.php';

/**
 * Extended Test Statistic Page GUI
 *
 * @author Frank Bauer <frank.bauer@fau.de>
 * @version $Id$
 *
 * @ilCtrl_IsCalledBy ilCodeQuestionESTIntegrationPageGUI: ilUIPluginRouterGUI
 */
class ilCodeQuestionESTIntegrationPageGUI
{
    /** @var ilCtrl $ctrl */
	protected $ctrl;

	/** @var ilTemplate $tpl */
	protected $tpl;

	/** @var ilCodeQuestionESTIntegrationPlugin $plugin */
	protected $plugin;

	/** @var ilObjTest $testObj */
	protected $testObj;

	/** @var ilCodeQuestionESTIntegration $estObj */
	protected $estObj;

	/**
	 * ilCodeQuestionESTIntegrationPageGUI constructor.
	 */
	public function __construct()
	{
		global $ilCtrl, $tpl, $lng;

		$this->ctrl = $ilCtrl;
		$this->tpl = $tpl;

		$lng->loadLanguageModule('assessment');

		$this->plugin = ilPlugin::getPluginObject(IL_COMP_SERVICE, 'UIComponent', 'uihk', 'CodeQuestionESTIntegration');
		$this->plugin->includeClass('class.ilCodeQuestionESTIntegration.php');
		$this->plugin->loadLanguageModule();

		$this->testObj = new ilObjTest($_GET['ref_id']);
		$this->estObj = new ilCodeQuestionESTIntegration($this->testObj, $this->plugin);
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
			echo "no permission";
            ilUtil::sendFailure($lng->txt("permission_denied"), true);
            ilUtil::redirect("goto.php?target=tst_".$this->testObj->getRefId());
		}
		
		$cmd = $this->ctrl->getCmd('showTestOverview');
		
		if ($_POST['cmd'] && $_POST['cmd']['uploadFiles']){
			/*$form = $this->getDragAndDropFileUploadForm();
			
			if ($form->checkInput())
        	{
				echo "IIIII";
			}*/
				
			$file = $_FILES['upload_files'];

			//foreach($files as $i=>$file)
			{
				
				if ($file['error']!=0){
					ilUtil::sendFailure($this->uploadCodeToMessage($file['error']), true);
					ilUtil::redirect("goto.php?target=tst_".$this->testObj->getRefId());
				}

				if (!file_exists($file['tmp_name'])){
					ilUtil::sendFailure($lng->txt('file_not_found'), true);
					ilUtil::redirect("goto.php?target=tst_".$this->testObj->getRefId());
				}

				$za = new ZipArchive();
				$za->open($file['tmp_name']);
				echo "numFiles: " . $za->numFiles . "\n";
				echo "status: " . $za->status  . "\n";
				echo "statusSys: " . $za->statusSys . "\n";
				echo "filename: " . $za->filename . "\n";
				echo "comment: " . $za->comment . "\n";

				for ($i=0; $i<$za->numFiles;$i++) {
					echo "index: $i\n";
					print_r($za->statIndex($i));
				}
				echo "numFile:" . $za->numFiles . "\n";
			}
			print_r($_POST);
			print_r($file);
			die;
		}
		switch ($cmd)
		{
			case 'showMainESTPage':
				$this->prepareOutput();
				$this->tpl->setContent($this->overviewContent());
				$this->tpl->show();
			break;
			case 'zip':
				$this->sendZIP();
			break;
			default:
                ilUtil::sendFailure($lng->txt("permission_denied"), true);
                ilUtil::redirect("goto.php?target=tst_".$this->testObj->getRefId());
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
		$form->setTarget("cld_blank_target");
		$form->setFormAction($ilCtrl->getFormAction($this, "uploadFiles"));
		$form->setTableWidth("100%");

		$item = new ilCustomInputGUI($lng->txt('archive_file'));		
		$item->setHTML('<input type="file" id="upload_files" name="upload_files">');
		$form->addItem($item);	
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

	protected function overviewContent(){
		global $ilCtrl, $ilDB;

		$data      = $this->testObj->getCompleteEvaluationData(TRUE);
		$ilCtrl->saveParameterByClass('ilCodeQuestionESTIntegrationPageGUI','ref_id');

		$tpl = $this->plugin->getTemplate('tpl.il_ui_uihk_uicodequestionest_main_page.html');
		$tpl->setVariable("PARTICIPANT_COUNT", count($data->getParticipants()));
		$tpl->setVariable("LINK_ZIP", $ilCtrl->getLinkTargetByClass(array('ilUIPluginRouterGUI','ilCodeQuestionESTIntegrationPageGUI')).'&cmd=zip');

		//echo $this->getFileUploadFormHTML()."<hr>";die;
		//$upload = $this->getFileUploadForm();
		$tpl->setVariable("FILE_UPLOAD", $this->getFileUploadForm()->getHTML());
		// make sure jQuery is loaded
		/*
		iljQueryUtil::initjQuery();

		$uniqueId = "ESTUpload";
		$submit_button_name = "ESTSubmit";
		$cancel_button_name = "ESTCancle";

		ilFileUploadGUI::initFileUpload();
		// load template
		$formtpl = new ilTemplate("tpl.prop_dndfiles.html", true, true, "Services/Form");
		$formtpl->setVariable("UPLOAD_ID", $uniqueId);

		// input
		$formtpl->setVariable("FILE_SELECT_ICON", ilObject::_getIcon("", "", "fold"));
		$formtpl->setVariable("TXT_SHOW_ALL_DETAILS", $this->plugin->txt('show_all_details')); 
		$formtpl->setVariable("TXT_HIDE_ALL_DETAILS", $this->plugin->txt('hide_all_details'));
		$formtpl->setVariable("TXT_SELECTED_FILES", $this->plugin->txt('selected_files'));
		$formtpl->setVariable("TXT_DRAG_FILES_HERE", $this->plugin->txt('drag_files_here'));
		$formtpl->setVariable("TXT_NUM_OF_SELECTED_FILES", $this->plugin->txt('num_of_selected_files'));
		$formtpl->setVariable("TXT_SELECT_FILES_FROM_COMPUTER", $this->plugin->txt('select_files_from_computer'));
		$formtpl->setVariable("TXT_OR", $this->plugin->txt('logic_or'));
		$formtpl->setVariable("INPUT_ACCEPT_SUFFIXES", array('zip'));

		// info
		$formtpl->setCurrentBlock("max_size");
		$formtpl->setVariable("TXT_MAX_SIZE", $this->plugin->txt("file_notice")." ".$this->getMaxFileSizeString());
		$formtpl->parseCurrentBlock();

		// create file upload object		
		$upload = new ilFileUploadGUI("ilFileUploadDropZone_" . $uniqueId, $uniqueId, false);
		//$upload->enableFormSubmit("ilFileUploadInput_" . $uniqueId, $submit_button_name, $cancel_button_name);
		$upload->setDropAreaId("ilFileUploadDropArea_" . $uniqueId);
		$upload->setFileListId("ilFileUploadList_" . $uniqueId);
		$upload->setFileSelectButtonId("ilFileUploadFileSelect_" . $uniqueId);
		
		
		$formtpl->setVariable("FILE_UPLOAD", $upload->getHTML());
		$tpl->setVariable("FILE_UPLOAD", $formtpl->get());
		*/

		/*foreach($data->getParticipants() as $active_id => $userdata)
		{
			// Do something with the participants				
			$pass = $userdata->getScoredPass();
			foreach($userdata->getQuestions($pass) as $question)
			{
				$objQuestion = $this->testObj->_instanciateQuestion($question["id"]);
				$solution = $objQuestion->getExportSolution($active_id, $pass);
				$this->estObj->updatePoints(
					$solution['active_fi'], 
					$solution['question_fi'], 
					$solution['pass'], 
					0.5, 
					$question['points'],
					'welcome');
				print_r($solution);
				print_r($question);
				print_r($objQuestion);
				print_r($ilDB);
				die;
			}
}*/

		return $tpl->get();					
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

		$this->tpl->getStandardTemplate();
		$this->tpl->setLocator();
		$this->tpl->setTitle($this->testObj->getPresentationTitle());
		$this->tpl->setDescription($this->testObj->getLongDescription());
		$this->tpl->setTitleIcon(ilObject::_getIcon('', 'big', 'tst'), $lng->txt('obj_tst'));
		$this->tpl->addCss($this->plugin->getStyleSheetLocation('uicodequestionest.css'));		

		return true;
	}

	function sendZIP(){
		/** @var ilAccessHandler $ilAccess */
		/** @var ilErrorHandling $ilErr */
		global $ilAccess, $ilErr, $lng;

		if (!$ilAccess->checkAccess('write','',$this->testObj->getRefId()))
		{
			ilUtil::sendFailure($lng->txt("permission_denied"), true);
            ilUtil::redirect("goto.php?target=tst_".$this->testObj->getRefId());
		}

		$zipFile  = tempnam(sys_get_temp_dir(), 'EST_');
		$err = $this->estObj->buildZIP($zipFile);

		if (!is_null($err))
		{
			ilUtil::sendFailure($err, true);
            ilUtil::redirect("goto.php?target=tst_".$this->testObj->getRefId());
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
		ilUtil::sendSuccess($this->lng->txt("download_created"), true);		
		$ilCtrl->redirect($this, "showMainESTPage");
		//die;		
	}
}
?>