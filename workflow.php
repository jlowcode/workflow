<?php

/**
 * Workflow
 * 
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.workflow
 * @copyright   Copyright (C) 2018-2024 Jlowcode Org - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

// Require the abstract plugin class
require_once COM_FABRIK_FRONTEND . '/models/plugin-form.php';
require_once COM_FABRIK_FRONTEND . '/models/list.php';
require_once JPATH_COMPONENT . '/controller.php';
require_once JPATH_PLUGINS . '/fabrik_element/field/field.php';
require_once JPATH_PLUGINS . '/fabrik_element/textarea/textarea.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Filesystem\Path;
use Joomla\CMS\Layout\FileLayout;

/**
 * Form workflow plugin
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.workflow
 * @since       3.0
 */
class PlgFabrik_FormWorkflow extends PlgFabrik_Form
{
    protected $listId;
    protected $listName;
    protected $requestListName;
    protected $requestListId;
    protected $requestListFormId;
    protected $isRequestList = false;
    protected $dbtable_request_sufixo;
    protected $fieldPrefix;
    protected $requestType;
    protected $easyadmin=false;

    protected $requests_table_attrs = Array(
        'req_id',
        'req_request_type_id',
        'req_user_id',
        'req_field_id',
        'req_created_date',
        'req_owner_id',
        'req_reviewer_id',
        'req_revision_date',
        'req_status',
        'req_description',
        'req_comment',
        'req_record_id',
        'req_approval',
        'req_file',
        'req_list_id',
        'form_data'
    );

    protected $statusLista = Array();
    protected $requestTypeText = Array();

    const REQUEST_TYPE_ADD_RECORD = 1;
    const REQUEST_TYPE_EDIT_RECORD = 2;
    const REQUEST_TYPE_DELETE_RECORD = 3;
    const REQUEST_TYPE_ADD_FIELD = 4;
    const REQUEST_TYPE_EDIT_FIELD = 5;

    protected $requestTypes = [1 => "add_record", 2 => "edit_record", 3 => "delete_record", 4 => "add_field", 5 => "edit_field"];

    const REQUESTS_TABLE_NAME = '#__fabrik_requests';

    /**
	 * Constructor
	 *
	 * @param   	Object 		&$subject 		The object to observe
	 * @param   	Array		$config   		An array that holds the plugin configuration
	 *
	 * @return		Null
	 */
	public function __construct(&$subject, $config) 
	{
        $this->subject = $subject;
        $this->configConstruct = $config;
		parent::__construct($subject, $config);
        
        $this->statusLista = Array(
            'verify' => Text::_('PLG_FORM_WORKFLOW_VERIFY'),
            'approved' => Text::_('PLG_FORM_WORKFLOW_APPROVED'),
            'not-approved' => Text::_('PLG_FORM_WORKFLOW_PRE_APPROVED'),
            'pre-approved' => Text::_('PLG_FORM_WORKFLOW_NOT_APPROVED'),
        );

        $this->requestTypeText = Array(
            'add_record' => Text::_('PLG_FORM_WORKFLOW_ADD_RECORD'),
			'edit_field_value' => Text::_('PLG_FORM_WORKFLOW_EDIT_FIELD_VALUE'),
			'delete_record' => Text::_('PLG_FORM_WORKFLOW_DELETE_RECORD'),
			'add_field' => Text::_('PLG_FORM_WORKFLOW_ADD_FIELD'),
			'edit_field' => Text::_('PLG_FORM_WORKFLOW_EDIT_FIELD')
        );
    }

    /**
     * Function that get the token from the session
     * 
     * @return      Null
     */
    public function onGetSessionToken()
    {
        echo Factory::getSession()->getFormToken();
    }

    /**
     * This function gets the request list from the server. Called by AJAX.
     * 
     * @return      Null
     */
    public function onGetRequestList()
    {
        jimport('joomla.access.access');

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Get params to find requests
        $approveOwn = $_REQUEST['approve_for_own_records'];
        $wfl_action = $_REQUEST['wfl_action'];
        $list_id = $_REQUEST['list_id'];
        $user_id = $_REQUEST['user_id'];
        $req_status = $_REQUEST['req_status'];
        $sequence =  $_REQUEST['sequence'];
        $allow_review_request =  $_REQUEST['allow_review_request'];

        $query = $db->getQuery(true);
        $query->select('req_id')
            ->select('req_user_id')
            ->select('u_req.name as req_user_name')
            ->select('u_req.email as req_user_email')
            ->select('IF(req_created_date = "0000-00-00 00:00:00", "", DATE_FORMAT(req_created_date, "%d/%m/%Y")) AS req_created_date')
            ->select('req_owner_id')
            ->select('u_owner.name as req_owner_name')
            ->select('req_reviewer_id')
            ->select('u_rev.name as req_reviewer_name')
            ->select('IF(req_revision_date = "0000-00-00 00:00:00", "", DATE_FORMAT(req_revision_date, "%d/%m/%Y")) AS req_revision_date')
            ->select('req_status')
            ->select('req_request_type_id')
            ->select('req_approval')
            ->select('req_comment')
            ->select('req_file')
            ->select('req_record_id')
            ->select('req_list_id')
            ->select('req_vote_approve')
            ->select('req_vote_disapprove')
            ->select('req_reviewers_votes')
            ->select('workflow_request_type.name as req_request_type_name')
            ->select('form_data')
            ->from($db->qn('#__fabrik_requests'))
            ->join('INNER', "workflow_request_type on (req_request_type_id = workflow_request_type.id)")
            ->join('INNER', $db->qn('#__users', 'u_req') . ' ON (' . $db->qn('req_user_id') . ' = ' . $db->qn('u_req.id') . ')')
            ->join('LEFT', $db->qn('#__users', 'u_owner') . ' ON (' . $db->qn('req_owner_id') . ' = ' . $db->qn('u_owner.id') . ')')
            ->join('LEFT', $db->qn('#__users', 'u_rev') . ' ON (' . $db->qn('req_reviewer_id') . ' = ' . $db->qn('u_rev.id') . ')')
            ->order("FIELD(req_status, 'verify', 'approved', 'not-approved', 'pre-approved')")
            ->where("req_list_id = '{$list_id}'");

        if ($wfl_action == 'list_requests') {
            $query->where("req_status = '{$req_status}'");
        }

        // Set search if exists
        if (isset($_REQUEST['search'])) {
            $search = $_REQUEST['search'];
            $query->where("(
                u_owner.name LIKE '{$search}%' OR
                u_rev.name LIKE '{$search}%' OR
                form_data LIKE '%{$search}%'              
            )");
        }

        // Verify if can view only own records
        if ($this->canViewRequests($allow_review_request) == 'only_own') {
            if ($approveOwn) {
                $query->where("(req_user_id = '{$user_id}' OR req_owner_id = '{$user_id}')");
            } else {
                $query->where("req_user_id = '{$user_id}'");
            }
        }

        // Verify if only wants count how many records and return it
        if (isset($_REQUEST['count']) && $_REQUEST['count'] == "1") {
            $db->setQuery($query);
            $r = $db->loadObjectList();
            $dados = array();

            foreach ($r as $row) {
                $dados[$row->req_status][] = (object)array('data' => $row);
            }

            if ($dados['verify']) {
                $howMany = count($dados['verify']);
            } else if ($dados['approved']) {
                $howMany = count($dados['approved']);
            } else if ($dados['pre-approved']) {
                $howMany = count($dados['pre-approved']);
            } else if ($dados['not-approved']) {
                $howMany = count($dados['not-approved']);
            }

            echo $howMany;
            return;
        }

        // Set pagination
        if (isset($_REQUEST['start']) && isset($_REQUEST['length'])) {
            $subQuery = $query;
            $query = $db->getQuery(true);
            $query->select('*')->from("($subQuery) AS results");
            $query->setLimit($_REQUEST['length'], $_REQUEST['start']);
        } else {
            $query->setLimit(5, 0);
        }

        // Set order_by
        if (isset($_REQUEST['order_by'])) {
            $query->order("{$_REQUEST['order_by']} $sequence");
        } else {
            $query->order("req_created_date asc");
        }

        $db->setQuery($query);
        $r = $db->loadObjectList();

        $dados = array();
        foreach ($r as $row) {
            $dados[$row->req_status][] = (object)array('data' => $row);
        }

        echo json_encode($dados);
    }

    /**
     * Get the last formData from #__fabrik_requests table to compare on approve a edit request
     * 
     * @return      Null
     */
    public function onGetLastRecordFormData()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $req_record_id = $_REQUEST['req_record_id'];
        $req_list_id = $_REQUEST['req_list_id'];

        $query = $db->getQuery(true);
        $query->select($db->qn("form_data"))
            ->from($db->qn("#__fabrik_requests"))
            ->where($db->qn("req_record_id") . ' = ' . "$req_record_id")
            ->where($db->qn("req_list_id") . ' = ' . "$req_list_id")
            ->where("(`req_status` = 'approved' or `req_status` = 'pre-approved')")
            ->order('req_id desc');
        $db->setQuery($query);

        echo $db->loadResult();
    }

    /**
     * This method get all plugins of each element from the list
     * 
     * @param       String              $list_id            Id of the list
     * 
     * @return      Object|Null
     */
    public function onGetElementsPlugin($list_id=null)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $filter = JFilterInput::getInstance();

        $request = $filter->clean($_REQUEST, 'array');
        $req_list_id = $list_id ? $list_id : $request['req_list_id'];

        $subQuery = $db->getQuery(true);
        $subQuery->select($db->qn('b.group_id'))
            ->from($db->qn('#__fabrik_lists', 'a'))
            ->join('INNER', $db->qn('#__fabrik_formgroup', 'b') .
                ' ON ' . $db->qn('a.form_id') . ' = ' . $db->qn('b.form_id'))
            ->where($db->qn('a.id') . ' = ' . $req_list_id);

        $db->setQuery($subQuery);
        $results = $db->loadObjectList();

        $allElements = Array();
        foreach ($results as $group) {
            $query = $db->getQuery(true);
            $group_id = $group->group_id;
            $query->select(Array($db->qn('name'), $db->qn('plugin'), $db->qn('params')))
                ->from($db->qn('#__fabrik_elements'))
                ->where($db->qn('group_id') . ' = ' . "$group_id");

            $db->setQuery($query);
            $results = $db->loadObjectList();
            $allElements[$group_id] = $results;
        }

        // Query to catch plugins type from all elements from a group_id
        $elements = new StdClass;
        foreach ($allElements as $results) {
            foreach ($results as $result) {
                $property = $result->name;
                $elements->$property['plugin'] = $result->plugin;
                if ($result->plugin == "databasejoin") {
                    $params = json_decode($result->params);
                    $elements->$property['join_db_name'] = $params->join_db_name;
                    $elements->$property['database_join_display_type'] = $params->database_join_display_type;
                    $elements->$property['join_val_column'] = $params->join_val_column;
                    $elements->$property['join_key_column'] = $params->join_key_column;
                } else if ($result->plugin == "tags") {
                    $params = json_decode($result->params);
                    $elements->$property['tags_dbname'] = $params->tags_dbname;
                }
            }
        }

        if (isset($list_id) && !empty($list_id)) {
            return $elements;
        } else {
            echo json_encode($elements);
        }
    }

    /**
     * This method get the requests by id of the request
     * 
     * @return      Null
     */
    public function onGetRequest()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $filter = JFilterInput::getInstance();

        $request = $filter->clean($_REQUEST, 'array');
        $req_id = $request['req_id'];

        $query = $db->getQuery(true);
        $query->select(array('a.*', 'b.name as req_user_name', 'c.name as req_owner_name', 'd.name as req_reviewer_id_raw'));
        $query->from($db->qn('#__fabrik_requests', 'a'));

        // If has req_id, set where
        if (isset($_GET['req_id'])) {
            $req_id = $_GET['req_id'];
            $query->join('LEFT', $db->qn('#__users', 'b') . ' ON (' . $db->qn('a.req_user_id') . ' = ' . $db->qn('b.id') . ')');
            $query->join('LEFT', $db->qn('#__users', 'c') . ' ON (' . $db->qn('a.req_owner_id') . ' = ' . $db->qn('c.id') . ')');
            $query->join('LEFT', $db->qn('#__users', 'd') . ' ON (' . $db->qn('a.req_reviewer_id') . ' = ' . $db->qn('d.id') . ')');
            $query->where($db->qn('req_id') . ' = ' . $db->q($req_id));
        } else {
            die(Text::_("PLG_FORM_WORKFLOW_ERROR_GETTING_REQUEST"));
        }

        $db->setQuery($query);
        $results = $db->loadObjectList();

        echo json_encode($results);
    }

    /**
     * This method provide the new name and the old name of the user
     * 
     * @return      Null
     */
    public function onGetUserValueBeforeAfter()
    {
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');

        $lastUserId = $request['last_user_id'];
        $newUserId = $request['new_user_id'];

        $last = Factory::getUser($lastUserId);
        $new = Factory::getUser($newUserId);

        $r = new StdClass;
        $r->last = $last->name;
        $r->new = $new->name;

        echo json_encode($r);
    }

    /**
     * This method updates the request record on the #__fabrik_requests table. Called by AJAX.
     * 
     * @return      Null
     */
    public function onProcessRequest()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $usuario = &Factory::getApplication()->getIdentity();

        $fieldsToUpdate = Array(
            "req_id",
            "req_approval",
            "req_comment",
            "req_file",
            "req_reviewer_id",
            "req_status",
            "req_vote_approve",
            "req_vote_disapprove",
            "req_reviewers_votes",
            "req_list_id",
        );

        $return = new StdClass;
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');
        $sendMail = $request["options"]["sendMail"];

        $requestData = $request['formData'][0];
        $data = $requestData;
        foreach ($requestData as $key => $value) {
            if (!in_array($key, $fieldsToUpdate)) {
                unset($requestData[$key]);
            }
        }

        if ($request["options"]["workflow_approval_by_votes"] == 1) {
            if ($requestData['req_status'] == 'approved' || $requestData['req_status'] == 'not-approved') {
                $requestData['req_approval'] = $requestData['req_status'] === 'approved' ? 1 : 0;
            }
            $requestData['req_reviewers_votes'] .= $usuario->id . ',';
        } else {
            $requestData['req_status'] = $requestData['req_approval'] === '1' ? 'approved' : 'not-approved';
        }

        $requestData['req_vote_approve'] =  $requestData['req_vote_approve'] === '' ? null : $requestData['req_vote_approve'];
        $requestData['req_vote_disapprove'] = $requestData['req_vote_disapprove'] === '' ? null : $requestData['req_vote_disapprove'];

        $requestData['req_reviewer_id'] = $requestData['req_reviewer_id'] === '' ? "{$usuario->id}" : $requestData['req_reviewer_id'];
        $requestData['req_revision_date'] = date("Y-m-d H:i:s");

        $obj = (object) $requestData;
        $results = $db->updateObject('#__fabrik_requests', $obj, 'req_id', false);
        $r = $this->saveNotification($requestData);
        $return->response = true;

        if($sendMail == true) {
            $this->enviarEmailRequestApproval($data, $data['req_record_id']);
        }

        echo json_encode($return);
    }

    /**
     * Function sends message texts to javascript file
     *
	 * @return  	Null
     */
    public function loadTranslationsOnJS()
    {
        Text::script('PLG_FORM_WORKFLOW_REQ_ID_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_OWNER_NAME_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_REQUEST_TYPE_NAME_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_USER_NAME_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_CREATED_DATE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_STATUS_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_RECORD_ID_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_LIST_ID_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_REVIEWER_ID_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_REVISION_DATE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_COMMENT_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_FILE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_APPROVAL_LABEL');

        Text::script('PLG_FORM_WORKFLOW_REQUEST_START_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_PREV_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_NEXT_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_END_LABEL');

        Text::script('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_ADD_TEXT');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_EDIT_TEXT');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_DELETE_TEXT');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_ADD_FIELD_TEXT');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_EDIT_FIELD_TEXT');

        Text::script('PLG_FORM_WORKFLOW_REQUEST_DATA_LABEL');
        Text::script('PLG_FORM_WORKFLOW_RECORD_DATA_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_COMMENT_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_FILE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_SAVE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_OWNER_LABEL');

        Text::script('PLG_FORM_WORKFLOW_VERIFY');
        Text::script('PLG_FORM_WORKFLOW_APPROVED');
        Text::script('PLG_FORM_WORKFLOW_PRE_APPROVED');
        Text::script('PLG_FORM_WORKFLOW_NOT_APPROVED');

        Text::script('PLG_FORM_WORKFLOW_ADD_RECORD');
        Text::script('PLG_FORM_WORKFLOW_EDIT_FIELD_VALUE');
        Text::script('PLG_FORM_WORKFLOW_DELETE_RECORD');
        Text::script('PLG_FORM_WORKFLOW_ADD_FIELD');
        Text::script('PLG_FORM_WORKFLOW_EDIT_FIELD');

        Text::script('PLG_FORM_WORKFLOW_LOG');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_VOTE_APPROVAL_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_VOTE_APPROVE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQ_VOTE_DISAPPROVE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_ERROR_LOAD_EASYADMIN_FILE');
        Text::script('PLG_FORM_WORKFLOW_SUCCESS');
        Text::script('PLG_FORM_WORKFLOW_NO_RECORDS_FOUND');
        Text::script('PLG_FORM_WORKFLOW_PARTIAL_VOTES');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_LABEL');
        Text::script('PLG_FORM_WORKFLOW_NEEDED_VOTES');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_DISAPPROVE_SECTION_LABEL');
        Text::script('PLG_FORM_WORKFLOW_VOTES_IN_FAVOR');
        Text::script('PLG_FORM_WORKFLOW_VOTES_AGAINST');
        Text::script('PLG_FORM_WORKFLOW_LOADING');
        Text::script('PLG_FORM_WORKFLOW_ORIGINAL_DATA');
        Text::script('PLG_FORM_WORKFLOW_VIEW');
        Text::script('PLG_FORM_WORKFLOW_PARTIAL_SCORE');
        Text::script('PLG_FORM_WORKFLOW_CLICK_HERE');
        Text::script('PLG_FORM_WORKFLOW_DELETE_RECORD_LIST');
    }

    /**
	 * Function to load the javascript code for the plugin
	 *
	 * @return  	Null
	 */
    protected function loadJs()
    {
        $wfl_action = filter_input(INPUT_GET, 'wfl_action', FILTER_SANITIZE_STRING);
        $show_request_id = filter_input(INPUT_GET, 'show_request_id', FILTER_SANITIZE_STRING);
        $options = new StdClass;

        if (isset($show_request_id) && !empty($show_request_id)) {
            $options->show_request_id = $show_request_id;
        }

        $sendMail = (bool) $this->params->get('workflow_send_mail');
        $options->requestListFormId = $this->requestListFormId;
        $options->listId = $this->listId;
        $options->listName = $this->listName;
        $options->user = $this->user;
        $options->requestsCount = $this->countRequestsNumber();
        $options->user->approve_for_own_records = $this->params->get('approve_for_own_records');
        $options->wfl_action = $wfl_action;
        $options->user->canApproveRequests = $this->canApproveRequests();
        $options->allow_review_request = $this->getParams()->get('allow_review_request');
        $options->workflow_owner_element = $this->params->get('workflow_owner_element');
        $options->workflow_ignore_elements = $this->params->get('workflow_ignore_elements');
        $options->workflow_approval_by_votes = $this->getParams()->get('workflow_approval_by_vote');
        $options->workflow_votes_to_approve = $this->getParams()->get('workflow_votes_to_approve');
        $options->workflow_votes_to_disapprove = $this->getParams()->get('workflow_votes_to_disapprove');
        $options->root_url = URI::root();
        $options->sendMail = $sendMail;
		$options->images = $this->getImages();
		$options->statusName = $this->statusLista;
		$options->requestTypeText = $this->requestTypeText;
        $options = json_encode($options);
        
        $jsFiles = Array();
        $jsFiles['Fabrik'] = 'media/com_fabrik/js/fabrik.js';
        $jsFiles['FabrikWorkflow'] = 'plugins/fabrik_form/workflow/workflow.js';
        $script = "var workflow = new FabrikWorkflow($options);";

        FabrikHelperHTML::script($jsFiles, $script);
    }

    /**
     * This method pass the list of requests to the view.
     * 
     * @return      Null
     */
    public function listRequests()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $this->loadJs();

        $approveOwn = (int) $this->params->get('approve_for_own_records');
        $wfl_action = filter_input(INPUT_GET, 'wfl_action', FILTER_SANITIZE_STRING);
        $req_status = filter_input(INPUT_GET, 'wfl_status', FILTER_SANITIZE_STRING);
        $req_status = $req_status ?: 'verify';

        $_REQUEST['wfl_action'] = $wfl_action;
        $_REQUEST['wfl_status'] = $req_status;

        $headings = array(
            'req_request_type_name' => Text::_('PLG_FORM_WORKFLOW_REQ_REQUEST_TYPE_NAME_LABEL'),
            'req_user_name' => Text::_('PLG_FORM_WORKFLOW_REQ_USER_NAME_LABEL'),
            'req_created_date' => Text::_('PLG_FORM_WORKFLOW_REQ_CREATED_DATE_LABEL'),
            'req_owner_name' => Text::_('PLG_FORM_WORKFLOW_REQ_OWNER_NAME_LABEL'),
            'req_reviewer_name' => Text::_('PLG_FORM_WORKFLOW_REQ_REVIEWER_ID_LABEL'),
            'req_revision_date' => Text::_('PLG_FORM_WORKFLOW_REQUEST_REVISION_DATE_LABEL'),
            'req_status' => Text::_('PLG_FORM_WORKFLOW_REQ_STATUS_LABEL'),
            'req_record_id' => Text::_('PLG_FORM_WORKFLOW_REQ_RECORD_ID_LABEL'),
            'req_approval' => Text::_('PLG_FORM_WORKFLOW_REQ_APPROVAL_LABEL'),
            'view' => Text::_('PLG_FORM_WORKFLOW_REQUEST_VIEW_LABEL')
        );

        $query = $db->getQuery(true);
        $query->select('req_id')
            ->select('req_user_id')
            ->select('u_req.name as req_user_name')
            ->select('req_created_date')
            ->select('req_owner_id')
            ->select('u_owner.name as req_owner_name')
            ->select('req_reviewer_id')
            ->select('u_rev.name as req_reviewer_name')
            ->select('req_revision_date')
            ->select('req_status')
            ->select('req_request_type_id')
            ->select('req_approval')
            ->select('req_record_id')
            ->select('req_vote_approve')
            ->select('req_vote_disapprove')
            ->select('req_reviewers_votes')
            ->select('workflow_request_type.name as req_request_type_name')
            ->from(self::REQUESTS_TABLE_NAME)
            ->join('INNER', "workflow_request_type on (req_request_type_id = workflow_request_type.id)")
            ->join('INNER', $db->qn('#__users', 'u_req') . ' ON (' . $db->qn('req_user_id') . ' = ' . $db->qn('u_req.id') . ')')
            ->join('LEFT', $db->qn('#__users', 'u_owner') . ' ON (' . $db->qn('req_owner_id') . ' = ' . $db->qn('u_owner.id') . ')')
            ->join('LEFT', $db->qn('#__users', 'u_rev') . ' ON (' . $db->qn('req_reviewer_id') . ' = ' . $db->qn('u_rev.id') . ')')
            ->order("FIELD(req_status, 'verify', 'approved', 'not-approved', 'pre-approved')")
            ->order("req_id asc")
            ->where("req_list_id = '{$this->listId}'");;

        if ($wfl_action == 'list_requests') {
            $query->where("req_status = '{$req_status}'");
        }

        if ($this->canViewRequests() == 'only_own') {
            if ($approveOwn) {
                $query->where("(req_user_id = '{$this->user->id}' OR req_owner_id = '{$this->user->id}')");
            } else {
                $query->where("req_user_id = '{$this->user->id}'");
            }
        }

        if ($this->canViewRequests() == 'only_own') {
            if ($this->params->get('approve_for_own_records')) {
                $query->where("req_user_id = '{$this->user->id}' OR req_owner_id = '{$this->user->id}'");
            } else {
                $query->where("req_user_id = '{$this->user->id}'");
            }
        }

        $db->setQuery($query);
        $r = $db->loadObjectList();

        $dados = array();
        foreach ($r as $row) {
            $dados[$row->req_status][] = (object)array('data' => $row);
        }

        // Pass the variables to the view
        $_REQUEST['workflow']['requests_tabs'] = $this->statusLista;
        $_REQUEST['workflow']['requests_headings'] = $headings;
        $_REQUEST['workflow']['requests_colCount'] = count($headings);
        $_REQUEST['workflow']['requests_list'] = $dados;
        $_REQUEST['workflow']['can_approve_requests'] = $this->canApproveRequests();
    }

    /**
     * This function get all reviewers of the workflow plugin for this form
     * 
     * @param       Int       $row        The row Id
     * 
     * @return      Array
     */
    public function getReviewers($row=null)
    {
        jimport('joomla.access.access');

        $db = Factory::getContainer()->get('DatabaseDriver');

        $reviewers_group_id = $this->params->get('allow_review_request') == null ? $_POST["options"]["allow_review_request"] : $this->params->get('allow_review_request');
        $approve_for_own_records = $this->params->get('approve_for_own_records') == null ? $_POST["options"]["approve_for_own_records"] : $this->params->get('approve_for_own_records');
        $workflow_owner_element = $this->params->get('workflow_owner_element') == null ? $_POST["options"]["workflow_owner_element"] : $this->params->get('workflow_owner_element');

        $query = $db->getQuery(true);
        $query->select($db->qn('rules'))
            ->from($db->qn('#__viewlevels'))
            ->where('id = ' . $reviewers_group_id);
        $db->setQuery($query);
        $r = $db->loadObjectList();

        $groups = json_decode($r[0]->rules);
        $allUsers = Array();
        foreach ($groups as $group) {
            $users = Access::getUsersByGroup($group);
            foreach ($users as $user) {
                $allUsers[] = $user;
            }
        }

        if ($approve_for_own_records == 1) {
            $owner_element_id = $workflow_owner_element;

            if (isset($owner_element_id) && !empty($owner_element_id)) {
                $db = Factory::getContainer()->get('DatabaseDriver');
                $query = $db->getQuery(true);
                $query->select($db->qn('name'))
                    ->from($db->qn('#__fabrik_elements'))
                    ->where('id = ' . $owner_element_id);
                $db->setQuery($query);
                $r = $db->loadObjectList();
                $owner_element_name = $r[0]->name;

                // Updates the owner_id var to the value
                if ($row) {
                    $owner_id = $row->{$this->model->form->db_table_name . '___' . $owner_element_name . '_raw'};
                } else {
                    $owner_id = $_REQUEST[$this->listName . '___' . $owner_element_name][0];
                }
                if ($owner_id == $this->user->id) {
                    $allUsers[] = $owner_id;
                }
            }
        }

        return $allUsers;
    }

    /**
     * Create the log object
     * 
     * @param       Array           $formData               The full formData array
     * @param       Boolean         $hasPermission          User has permission or not?
     * 
     * @return      Boolean
     */
    public function createLog($formData, $hasPermission)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $app = Factory::getApplication();

        // Configure models to use in easyadmin context
        if($this->easyadmin) {
            $listId = $formData['easyadmin_modal___listid'];
            $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
            $listModel->setId($listId);
            $formModel = $listModel->getFormModel();
            $this->params = $formModel->getParams();
            $this->fieldPrefix = 'req_';
            $this->listId = $listId;
        }

        switch ($this->requestType) {
            // If the request type is add record we must set the user element as the register owner
            case self::REQUEST_TYPE_ADD_RECORD:
                $owner_element_id = $this->params->get('workflow_owner_element');
                if (isset($owner_element_id) && !empty($owner_element_id)) {
                    $query = $db->getQuery(true);
                    $query->select($db->qn('name'))
                        ->from($db->qn('#__fabrik_elements'))
                        ->where('id = ' . $owner_element_id);
                    $db->setQuery($query);
                    $r = $db->loadObjectList();

                    $owner_element_name = $r[0]->name;
                    $owner_id = $formData[$this->listName . '___' . $owner_element_name][0];
                }
                break;
            
            // If the request type is edit record we must set the last owner as the register owner
            case self::REQUEST_TYPE_EDIT_RECORD:
                $req_record_id = $formData['rowid'];
                $query = $db->getQuery(true);
                $query->select(array($db->qn('req_owner_id'), $db->qn("form_data")))
                    ->from($db->qn("#__fabrik_requests"))
                    ->where($db->qn("req_record_id") . ' = ' . "$req_record_id")
                    ->where("(`req_status` = 'approved' or `req_status` = 'pre-approved')")
                    ->order('req_id desc');
                $db->setQuery($query);
                $results = $db->loadObjectList();

                if (!$results[0]->req_owner_id > 0) {
                    $owner_element_id = $this->params->get('workflow_owner_element');
                    if (isset($owner_element_id) && !empty($owner_element_id)) {
                        $query = $db->getQuery(true);
                        $query->select($db->qn('name'))
                            ->from($db->qn('#__fabrik_elements'))
                            ->where('id = ' . $owner_element_id);
                        $db->setQuery($query);
                        $r = $db->loadObjectList();
                        $owner_element_name = $r[0]->name;

                        $query = $db->getQuery(true);
                        $query->select(Array($db->qn($owner_element_name) . ' as value',))
                            ->from($db->qn($this->listName))
                            ->where('id = ' . $formData['rowid']);
                        $db->setQuery($query);
                        $r = $db->loadObjectList();

                        $owner_id = $r[0]->value;
                        $date_time = date('Y-m-d H:i:s');

                        $preApprovedLog = $this->createLogPreApproved($formData, $owner_id, $owner_id, $date_time);
                        $this->saveLog($preApprovedLog);
                    }
                } else {
                    $owner_id = $results[0]->req_owner_id;
                }
                break;

            // If the request type is delete record we must set the request owner as the register owner
            case self::REQUEST_TYPE_DELETE_RECORD:
                $owner_id = $formData["owner_id_raw"];
                break;

            // If the request type is add field we must set the user as the register owner
            case self::REQUEST_TYPE_ADD_FIELD:
                $owner_id = $this->user->id;
                break;
            
            // If the request type is edit field we must set the element owner as the register owner
            case self::REQUEST_TYPE_EDIT_FIELD:
                $element = $listModel->getElements('id', true, false)[$formData['easyadmin_modal___valIdEl']];
                $owner_id = $element->element->created_by;
                break;
        }

        if (isset($owner_id) && !empty($owner_id)) {
            $formData["owner_id"] = $owner_id;
        } else {
            die(Text::_("PLG_FORM_WORKFLOW_OWNER_NOT_SET"));
        }

        $logData = $this->getFormDataToLog($formData, $hasPermission);
        if (!$this->saveLog($logData)) {
            die(Text::_('PLG_FORM_WORKFLOW_PROCESS_LOG_FAIL'));
        }

        if ($this->params->get('workflow_send_mail') == '1'){
            $this->enviarEmailRequest($logData);
        }

        $this->saveNotification($logData);
        if (!$hasPermission) {
            switch ($this->requestType) {
                case self::REQUEST_TYPE_DELETE_RECORD:
                    $app->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_DELETE_SUCESS_MESSAGE'), 'message');
                    break;

                case self::REQUEST_TYPE_DELETE_RECORD:
                    $app->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_CREATE_SUCESS_MESSAGE'), 'message');
                    break;

                default:
                    $app->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_EDIT_SUCESS_MESSAGE'), 'message');
                    break;
            }

            return false;
        }

        return true;
    }

    /**
     * This method saves a request in fabrik_workflow table.
     * 
     * @param       Array          $formData       The formData array
     * 
     * @return      Boolean
     */
    public function saveLog($formData)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $mainListFormData = new StdClass;

        // Separates requestData and formData
        $query = $db->getQuery(true);
        $query->insert(self::REQUESTS_TABLE_NAME);
        foreach ($formData as $k => $v) {
            if (strpos($k, $this->fieldPrefix) !== false) {
                if (!empty($v)) {
                    $query->set("{$k} = " . $db->q($v));
                }
            } else {
                if (!empty($v) || in_array($this->requestType, ['4', '5'])) {
                    if (end(explode('_', $k)) == 'orig') {
                        $k = array_shift(explode('_orig', $k));
                    }
                    $mainListFormData->$k = $v;
                }
            }
        }

        $mainListFormDataJson = json_encode($mainListFormData);

        $query->set("form_data = " . $db->q($mainListFormDataJson));
        $db->setQuery($query);

        try {
            $db->execute();
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * 
     * 
     * @param       Array          $formData       The formData array
     * 
     * @return      Boolean
     */
    public function saveNotification($formData)
    {
        JLoader::register('FieldsHelper', JPATH_ADMINISTRATOR . '/components/com_fields/helpers/fields.php');
        JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fields/models', 'FieldsModel');

        $fieldModel = JModelLegacy::getInstance('Field', 'FieldsModel', array('ignore_request' => true));
        $reviewrs_id = $this->getReviewers();

        foreach ($reviewrs_id as $id) {
            $field_id = 1;
            $values = json_decode($fieldModel->getFieldValue($field_id, $id), true);
            $is_vote = $this->getParams()->get('workflow_approval_by_vote');

            if ($is_vote == 1) {
                if ($formData['req_vote_approve'] != '' || $formData['req_vote_disapprove'] != '') {
                    $is_vote = 1;
                }
            }

            if (($formData['req_status'] == "verify") && (!isset($values['requisicoes']) || count($values['requisicoes']) == 0)) {
                $this->newNotification($formData, $field_id, $id, 0);
            } else {
                foreach ($values['requisicoes'] as $k => $v) {
                    $validador = true;
                    if ($v['lista'] == $formData["req_list_id"]) {
                        $validador = false;
                        
                        // Remove only from current user if it is voting
                        if (($formData['req_status'] == "verify") && ($is_vote == 0)) {
                            $values['requisicoes'][$k]['qtd']  = $v['qtd'] + 1;
                        } else if ($formData['req_status'] != "pre-approved"){
                            $values['requisicoes'][$k]['qtd'] = $v['qtd'] - 1;
                            if ($values['requisicoes'][$k]['qtd'] == 0) {
                                unset($values['requisicoes'][$k]);
                            }
                        }

                        $value = json_encode($values);
                        $fieldModel->setFieldValue($field_id, $id, $value);
                        break 1;
                    } 
                }

                if ($validador && ($formData['req_status'] == "verify")) {
                    $values['requisicoes'][$k + 1]['lista'] = intval($formData["req_list_id"]);
                    $values['requisicoes'][$k + 1]['qtd'] = 1;
                    $value = json_encode($values);
                    $fieldModel->setFieldValue($field_id, $id, $value);
                }
            }
        }

        return true;
    }

    /**
     * 
     * 
     * @param       Array       $formData       The formData array
     * @param       Int         $field_id       The id of the field
     * @param       Int         $user_id        The id of the user
     * @param       Int         $k              Repeat counter value
     * 
     * @return      Null
     */
    public function newNotification($formData, $field_id, $user_id, $k)
    {
        JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fields/models', 'FieldsModel');
        $fieldModel = JModelLegacy::getInstance('Field', 'FieldsModel', Array('ignore_request' => true));

        $value = new StdClass;
        $value->requisicoes[$k]['lista'] = intval($formData["req_list_id"]);
        $value->requisicoes[$k]['qtd'] = 1;
        $value = json_encode($value);

        $fieldModel->setFieldValue($field_id, $user_id, $value);
    }

    /**
     * This method count the requests
     * 
     * @return      Boolean
     */
    public function countRequests()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $approveOwn = (int)$this->params->get('approve_for_own_records');

        $status = 'verify';
        $whereUser = '';
        $whereList = "AND req_list_id = '{$this->listId}'";

        if ($this->canViewRequests() == 'only_own') {
            $whereUser = "AND req_user_id = '{$this->user->id}'";
        }

        if ($this->canViewRequests() == 'only_own') {
            if ($approveOwn) {
                $whereUser = "AND (req_user_id = '{$this->user->id}' OR req_owner_id = '{$this->user->id}')";
            } else {
                $whereUser = "AND req_user_id = '{$this->user->id}'";
            }
        }

        try {
            $requestList = '#__fabrik_requests';
            $status = 'verify';

            $query = $db->getQuery(true);
            $query->clear();
            $sql = "SELECT (
                    -- New registration requests
                    COALESCE((SELECT count(req_id) FROM {$requestList} WHERE req_status = '{$status}' 
                        AND (req_record_id IS NULL OR req_record_id = 0) {$whereUser} {$whereList}), 0) 
                    
                    -- Record change/deletion requests
                    + COALESCE((SELECT count(req_id) FROM {$requestList} WHERE req_status = '{$status}' 
                        AND (req_record_id IS NOT NULL AND req_record_id <> 0) {$whereUser} {$whereList}), 0)
            ) as 'events'";
            $db->setQuery($sql);
            $r = $db->loadResult();

            $_REQUEST['workflow']['requests_count'] = $r;
            $_REQUEST['workflow']['label_request_aproval'] = Text::_('PLG_FORM_WORKFLOW_LABEL_REQUEST_APROVAL');
            $_REQUEST['workflow']['label_request_view'] = Text::_('PLG_FORM_WORKFLOW_LABEL_REQUEST_VIEW');

            // Parameter for list and form url (depends on whether frontend or backend)
            $opt = array('list' => '&view=list', 'form' => '&view=form', 'details' => '&view=details');
            if (stripos($_SERVER['REQUEST_URI'], 'administrator') !== false) {
                $opt = array('list' => 'task=list.view', 'form' => 'task=list.form', 'details' => 'task=details.view');
            }

            $_REQUEST['workflow']['list_link'] = $this->getFriendlyUrl($this->listId, 'list');
            $_REQUEST['workflow']['requests_link'] =  $_REQUEST['workflow']['listLinkUrl'] . "?wfl_action=list_requests#eventsContainer";
            $_REQUEST['workflow']['requests_form_link'] = $this->getFriendlyUrl($this->listId, 'form', $this->requestListFormId);
            $_REQUEST['workflow']['requests_details_link'] = "index.php?option=com_fabrik&{$opt['details']}&formid={$this->requestListFormId}&rowid=";

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * This method count the requests
     * 
     * @return      Int|Boolean
     */
    public function countRequestsNumber()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $approveOwn = (int)$this->params->get('approve_for_own_records');
        $status = 'verify';
        $whereUser = '';

        if ($this->canViewRequests() == 'only_own') {
            $whereUser = "AND req_user_id = '{$this->user->id}'";
        }

        if ($this->canViewRequests() == 'only_own') {
            if ($approveOwn) {
                $whereUser = "AND (req_user_id = '{$this->user->id}' OR req_owner_id = '{$this->user->id}')";
            } else {
                $whereUser = "AND req_user_id = '{$this->user->id}'";
            }
        }

        try {
            $requestList = '#__fabrik_requests';
            $status = 'verify';

            $query = $db->getQuery(true);
            $query->clear();
            $sql = "SELECT (
                    -- New registration requests
                    COALESCE((SELECT count(req_id) FROM {$requestList} WHERE req_status = '{$status}' 
                        AND (req_record_id IS NULL OR req_record_id = 0) {$whereUser}), 0) 
                    
                    -- Record change/deletion requests
                    + COALESCE((SELECT count(req_id) FROM {$requestList} WHERE req_status = '{$status}' 
                        AND (req_record_id IS NOT NULL AND req_record_id <> 0) {$whereUser}), 0)
            ) as 'events'";
            $db->setQuery($sql);
            $r = $db->loadResult();

            return $r;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Add the heading information to the Fabrik list, so as to include a column for the add to cart link
     *
     * @return      Null|Boolean
     */
    public function onGetPluginRowHeadings()
    {
        // Execute the method only once
        if (isset($_REQUEST['workflow']['init']) && $_REQUEST['workflow']['init'] == true) {
            return false;
        }

		$this->setImages();
        $this->init();
        if ($this->isRequestList()) {
            return true;
        }

        $this->checkAddRequestButton();
        $this->checkEventsButton();
        $this->listRequests();

        $_REQUEST['workflow']['init'] = true;
    }

    /**
     * This method process the formData array to save by fabrik controller
     * 
     * @param       Array       $formData       The formData array
     * 
     * @return      Array
     */
    public function processFormDataToSave($formData)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $elementsPlugins = $this->onGetElementsPlugin($formData['listid']);
        $elementModels = $this->getListElementModels();
        $listModel = $this->getModel()->getListModel();
        $formModel = $this->getModel();
       
        foreach ($elementsPlugins as $key => $value) {
            $completeElName = $this->listName . "___" . $key;

            if ($value['plugin'] == 'databasejoin') {
                $join_key_column = $value['join_key_column'];
                $join_val_column = $value['join_val_column'];
                $join_db_name = $value['join_db_name'];
                $ids = $formData[$completeElName];

                if (!empty($ids)) {
                    // Check renders and get the values from ids
                    if (
                        $value['database_join_display_type'] == 'multilist' ||
                        $value['database_join_display_type'] == 'checkbox' ||
                        ($value['database_join_display_type'] == 'auto-complete' && $ids[0] != '') ||
                        ($value['database_join_display_type'] == 'dropdown' && $ids[0] != '') ||
                        ($value['database_join_display_type'] == 'radio' && $ids[0] != '')
                    ) {
                        $query = $db->getQuery(true);
                        $whereClauses = Array();
                        foreach ($ids as $id) {
                            $whereClauses[] = $db->qn($join_key_column) . ' = ' . $db->q($id);
                        }

                        $query->select(array($db->qn($join_val_column) . ' as value'))
                            ->from($db->qn($join_db_name))
                            ->where($whereClauses, 'OR');
                        $db->setQuery($query);
                        $results = $db->loadObjectList();

                        $rawValues = Array();
                        foreach ($results as $v) {
                            $rawValues[] = $v->value;
                        }

                        $formData[$completeElName . "_value"] = $rawValues;
                    }
                }
            } else if ($value['plugin'] == 'fileupload') {
                foreach ($elementModels as $elementModel) {
                    if ($elementModel->getFullName() == $completeElName) {
                        $element = $elementModel->getElement();
                        $params = $elementModel->getParams();
                        $storage = $elementModel->getStorage();
                        $folder = $params->get('ul_directory');

                        // If not authorized registered
                        if (!in_array($this->user->id, $this->getReviewers())) {
                            // If ajax registered
                            if ($elementModel->getParams()->get('ajax_upload', '0') === '1') {
                                $key       = 'fabrik.form.fileupload.files.' . $elementModel->getId();
                                $ajaxFiles = $this->session->get($key, []);
                                $ajaxFiles['path'] = $folder;

                                if ($ajaxFiles) {
                                    if (is_array($ajaxFiles['name'])) {
                                        $workflowElementUploadName = $this->listName . '_FILE_' . $completeElName;
                                        $formModel->formData[$workflowElementUploadName] = $ajaxFiles;
                                        $formData[$workflowElementUploadName] = $ajaxFiles;
                                    
                                        $_FILES[$element->name] = $ajaxFiles;
                                        $fileData = $_FILES[$element->name]['name'];

                                        $completeElNameData = [];
                                        foreach ($fileData as $i => $f) {
                                            $file      = array(
                                                'name'     => $_FILES[$element->name]['name'][$i],
                                                'type'     => $_FILES[$element->name]['type'][$i],
                                                'tmp_name' => $_FILES[$element->name]['tmp_name'][$i],
                                                'error'    => $_FILES[$element->name]['error'][$i],
                                                'size'     => $_FILES[$element->name]['size'][$i],
                                                'path'     => $folder
                                            );

                                            array_push($completeElNameData, Path::clean($folder . $file['name']));
                                            $tmpFile  = $file['tmp_name'];

                                            if ($storage->appendServerPath()) {
                                                $folderPath = JPATH_SITE . '/' . $folder . '/' . $file['name'];
                                            }
                                            $filePath = Path::clean($folderPath);
                                            copy($tmpFile, $filePath);
                                        }

                                        $workflowElementUploadName = $this->listName . '_FILE_' . $completeElName;
                                        $formModel->updateFormData($completeElName, $completeElNameData);
                                        $formModel->updateFormData($completeElName . '_raw', $completeElNameData);
                                        $formData[$completeElName] = $completeElNameData;
                                        $formData[$completeElName . '_raw'] = $completeElNameData;
                                        $formModel->updateFormData($workflowElementUploadName, $file);
                                        $formModel->formData[$workflowElementUploadName] = $file;
                                    }
                                } else {
                                    $file  = Array(
                                        'name'     => $ajaxFiles['name'][0],
                                        'type'     => $ajaxFiles['type'][0],
                                        'tmp_name' => $ajaxFiles['tmp_name'][0],
                                        'error'    => $ajaxFiles['error'][0],
                                        'size'     => $ajaxFiles['size'][0],
                                        'path'     => $folder
                                    );

                                    $workflowElementUploadName = $this->listName . '_FILE_' . $completeElName;
                                    $formModel->formData[$workflowElementUploadName] = $file;
                                    $formData[$workflowElementUploadName] = $file;
                                    $completeElNameData = Path::clean($folder . $file['name']);
                                    $formModel->updateFormData($completeElName, $completeElNameData);
                                    $formModel->updateFormData($completeElName . '_raw', $completeElNameData);
                                    $formModel->updateFormData($workflowElementUploadName, $file);
                                    $tmpFile  = $file['tmp_name'];

                                    if(strlen($file['name']) > 0) {
                                        $formData[$completeElName] = $folder . $file['name'];
                                    }

                                    if ($storage->appendServerPath()) {
                                        $folder = JPATH_SITE . '/' . $folder;
                                    }

                                    $folder = $folder . '/' . $file['name'];
                                    $filePath = Path::clean($folder);
                                    copy($tmpFile, $filePath);
                                }
              
                            // If not ajax
                            }  else {
                                // If the request is registered
                                if (array_key_exists($completeElName, $_FILES) && $_FILES[$completeElName]['name'] != '') {
                                    $file  = Array(
                                        'name' => $_FILES[$completeElName]['name'],
                                        'type' => $_FILES[$completeElName]['type'],
                                        'tmp_name' => $_FILES[$completeElName]['tmp_name'],
                                        'error' => $_FILES[$completeElName]['error'],
                                        'size' => $_FILES[$completeElName]['size'],
                                        'path' => $folder
                                    );
                                    
                                    $workflowElementUploadName = $this->listName . '_FILE_' . $completeElName;
                                    $formModel->formData[$workflowElementUploadName] = $file;
                                    $completeElNameData = Path::clean($folder . $file['name']);
                                    $formData[$workflowElementUploadName] = $file;
                                    $tmpFile  = $file['tmp_name'];
               
                                    if ($storage->appendServerPath()) {
                                        $folder = JPATH_SITE . '/' . $folder;
                                    }

                                    $folder = $folder . '/' . $file['name'];
                                    $filePath = Path::clean($folder);

                                    copy($tmpFile, $filePath);
                                    $formModel->updateFormData($completeElName, $completeElNameData);
                                    $formModel->updateFormData($completeElName . '_raw', $completeElNameData);
                                    $formData[$completeElName . '_raw'] = $completeElNameData;
                                    $formData[$completeElName] = $completeElNameData;
                                }
                            }

                        // If authorized
                        } else {
                            // If we are approving request
                            if (empty($_FILES)) {
                                // If you already have the data saved from the registered request
                                if (isset($formData[$this->listName . '_FILE_' . $completeElName])) {
                                    if ($elementModel->getParams()->get('ajax_upload', '0') === '1') {
                                        $db_table_name = $this->listName . '_repeat_' . $element->name;

                                        foreach ($formData[$completeElName] as $i => $f) {
                                            $oRecord = (object) [
                                                'parent_id'   => $formData['rowid'],
                                                $element->name  => $formData[$completeElName][$i]
                                            ];
                                            $listModel->insertObject($db_table_name, $oRecord, false);
                                        }
                                    } else {
                                        $_FILES[$completeElName]['name'] = $formData[$this->listName . '_FILE_' . $completeElName]['name'];
                                        $_FILES[$completeElName]['type'] = $formData[$this->listName . '_FILE_' . $completeElName]['type'];
                                        $_FILES[$completeElName]['tmp_name'] = $formData[$this->listName . '_FILE_' . $completeElName]['tmp_name'];
                                        $_FILES[$completeElName]['error'] = $formData[$this->listName . '_FILE_' . $completeElName]['error'];
                                        $_FILES[$completeElName]['size'] = $formData[$this->listName . '_FILE_' . $completeElName]['size'];

                                        $_FILES[$completeElName]['workflow'] = '1';
                                        $imagePath = JPATH_SITE . '/' . $folder . '/' . $_FILES[$completeElName]['name'];
                                        $imagePath = Path::clean($imagePath);
                                        $newPath =  $_FILES[$completeElName]['tmp_name'];
                                        copy($newPath, $imagePath);
                                        unset($formData[$this->listName . '_FILE_' . $completeElName]);
                                        $completeElNameData = Path::clean($folder . $_FILES[$completeElName]['name']);
                                        $formData[$completeElName] = $completeElNameData;
                                        $formModel->updateFormData($completeElName, $completeElNameData);
                                    }
                                
                                // Means it is empty or it is ajax
                                } else {
                                    if ($elementModel->getParams()->get('ajax_upload', '0') === '1') {
                                        $key       = 'fabrik.form.fileupload.files.' . $elementModel->getId();
                                        $ajaxFiles = $this->session->get($key, []);
                                        if ($ajaxFiles != null) {
                                            $file  = Array(
                                                'name'     => $ajaxFiles['name'][0],
                                                'type'     => $ajaxFiles['type'][0],
                                                'tmp_name' => $ajaxFiles['tmp_name'][0],
                                                'error'    => $ajaxFiles['error'][0],
                                                'size'     => $ajaxFiles['size'][0],
                                                'path'     => $folder

                                            );

                                            $workflowElementUploadName = $this->listName . '_FILE_' . $completeElName;
                                            $formData[$workflowElementUploadName] = $file;
                                            $completeElNameData = Path::clean($folder . $file['name']);
                                            $formData[$completeElName] = $completeElNameData;
                                            $formData[$completeElName . '_raw'] = $completeElNameData;

                                            $formModel->updateFormData($completeElName, $completeElNameData);
                                            $formModel->updateFormData($completeElName . '_raw', $completeElNameData);
                                            $formModel->updateFormData($workflowElementUploadName, $file);

                                            $imagePath = JPATH_SITE . '/' . $completeElNameData;
                                            $imagePath = Path::clean($imagePath);
                                            $newPath =  $file['tmp_name'];
                                            copy($newPath, $imagePath);
                                        }
                                    }
                                }
                            
                            // If we have authorization and it is not empty
                            } else {
                                if (array_key_exists($completeElName, $_FILES) && $_FILES[$completeElName]['name'] != '') {
                                    $file  = Array(
                                        'name' => $_FILES[$completeElName]['name'],
                                        'type' => $_FILES[$completeElName]['type'],
                                        'tmp_name' => $_FILES[$completeElName]['tmp_name'],
                                        'error' => $_FILES[$completeElName]['error'],
                                        'size' => $_FILES[$completeElName]['size'],
                                        'path' => $folder
                                    );

                                    $tmpFile  = $file['tmp_name'];
                                    $completeElNameData = Path::clean($folder . $file['name']);

                                    if(strlen($file['name']) > 0) {
                                        $formModel->formData[$completeElName] = $folder . $file['name'];
                                    }

                                    if ($storage->appendServerPath()) {
                                        $folder = JPATH_SITE . '/' . $folder;
                                    }

                                    $workflowElementUploadName = $this->listName . '_FILE_' . $completeElName;
                                    $formModel->formData[$workflowElementUploadName] = $file;
                                    $folder = $folder . '/' . $file['name'];
                                    $filePath = Path::clean($folder);
                                    copy($tmpFile, $filePath);
                                }
                            }
                        }
                    }
                }

                if (isset($formData[$completeElName]['cropdata'])) {
                    $imageLink = array_keys($formData[$completeElName]['id'])[0];

                    $formData[$completeElName]['cropdata'] = Array($imageLink => 0);
                    $formData[$completeElName]['crop'] = Array($imageLink => 0);
                    $formData[$completeElName . "_raw"]['cropdata'] = Array($imageLink => 0);
                    $formData[$completeElName . "_raw"]['crop'] = Array($imageLink => 0);
                }

            } else if ($value['plugin'] == 'tags') {
                $ids = $formData[$completeElName];
                if (!empty($ids) && $ids[0] != '') {
                    $tags_dbname = $value['tags_dbname'];
                    $query = $db->getQuery(true);

                    $whereClauses = Array();
                    foreach ($ids as $id) {
                        $whereClauses[] = $db->qn('id') . ' = ' . $id;
                    }

                    $query->select(array($db->qn('title')))
                        ->from($db->qn($tags_dbname))
                        ->where($whereClauses, 'OR');
                    $db->setQuery($query);
                    $results = $db->loadObjectList();

                    $rawValues = Array();
                    foreach ($results as $v) {
                        $rawValues[] = $v->title;
                    }

                    $formData[$completeElName . "_value"] = $rawValues;
                }
            }
        }

        $ajaxParams = array(
            'listid', 'listref', 'rowid', 'Itemid', 'option', 'task', 'isMambot', 'formid',
            'returntoform', 'fabrik_referrer', 'fabrik_ajax', 'package', 'packageId', 'nodata',
            'format',
        );

        // Foreach that catchs only elements of the list if is no raw
        $newFormData = array();
        foreach ($formData as $key => $value) {
            if ((strpos($key, $this->listName) !== false ||
                strpos($key, 'repeat_group') !== false ||
                strpos($key, 'hiddenElements') !== false)) {

                $newFormData[$key] = $value;
            }
        }

        // Foreach that catchs params to make the request to save the register later
        foreach ($ajaxParams as $value) {
            $newFormData[$value] = $formData[$value];
        }

        return $newFormData;
    }

    /**
     * Run right at the beginning of the form processing
     *
     * @return      Boolean
     */
    public function onBeforeProcess()
    {
        $this->init();
        $isReview = false;

        $formModel = $this->getModel();
        $fullFormData = $formModel->fullFormData;
        $processedFormData = $this->processFormDataToSave($fullFormData);
        $hasPermission = $this->hasPermission($processedFormData);
        $this->setRequestType($fullFormData, false);

        // Set isReview to true if is review
        if (isset($fullFormData['review']) && $fullFormData['review']) {
            $isReview = true;
        }

        // If the user has permission to add the register as pre-approved
        if ($hasPermission) {
            if ($isReview) {
                $this->isReview = true;
                $this->req_id = $fullFormData['req_id'];
            }
        } else {
            return $this->createLog($processedFormData, $hasPermission);
        }
    }

    /**
     * Returns if can bypass workflow
     *
     * @return      Boolean
     */
    public function canAdd()
    {
        $listModel = $this->getModel()->getListModel();
        $params = $listModel->getParams();
        $groups = $this->user->getAuthorisedViewLevels();
        $canAdd = in_array($params->get('allow_add'), $groups);

        return $canAdd;
    }

    /**
     * Run right at the end of the form processing, form needs to be set to record in database for this to hook to be called
     *
     * @return      Null|Boolean
     */
    public function onAfterProcess()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $formModel = $this->getModel();
        $listModel = $formModel->getListModel();

        $owner_element_id = $this->params->get('workflow_owner_element');
        $owner_element = $listModel->getElements('id');
        $owner_element_name = $owner_element[$owner_element_id]->element->name;
        $table_name = $formModel->getTableName();

        $pk = $listModel->getPrimaryKeyAndExtra();
        $id_raw = $formModel->fullFormData[$table_name . '___' . $pk[0]['colname'] . '_raw'];
        $owner_id = $formModel->fullFormData[$table_name . '___' . $owner_element_name][0];

        $query = $db->getQuery(true);
        $query->set("{$owner_element_name} = " . $db->q($owner_id))
            ->update($table_name)
            ->where("{$pk[0]['colname']} = " . $db->q($id_raw));
        $db->setQuery($query);
        $db->execute();

        if (isset($this->isReview) && $this->isReview) {
            $row_id = $formModel->fullFormData['rowid'];
            $req_id = $formModel->fullFormData['req_id'];

            $query = $db->getQuery(true);
            $query->clear();
            $query->set("req_record_id = " . $db->q($row_id))
                ->set("req_reviewer_id = " . $db->q($this->user->id))
                ->update('#__fabrik_requests')
                ->where("req_id = " . $db->q($req_id));
            $db->setQuery($query);
            $db->execute();
        } else {
            $fullFormData = $formModel->fullFormData;
            $processedFormData = $this->processFormDataToSave($fullFormData);
            $hasPermission = $this->hasPermission($processedFormData);

            return $this->createLog($processedFormData, $hasPermission);
        }
    }

    /**
	 * Init function
	 *
	 * @return  	Null
	 */
    private function init()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $this->loadTranslationsOnJS();

        try {
            $formModel = $this->getModel();
            $form = $formModel->getForm();
            $listId = $formModel->getListModel()->getId();

            $db->setQuery("SELECT " . $db->qn('db_table_name') . " FROM " . $db->qn('#__fabrik_lists') . " WHERE " . $db->qn('form_id') . " = " . (int) $form->id);
            $listName = $db->loadResult();
        } catch (Exception $e) {
            $listId = null;
            $listName = '';
        }

        $this->listId = $listId;
        $this->listName = $listName;

        $this->dbtable_request_sufixo = '_request';
        $this->checkIsRequestList();

        if (!$this->isRequestList()) {
            $this->requestListName = $this->listName . $this->dbtable_request_sufixo;

            try {
                $formModel = $this->getModel();
                $form = $formModel->getForm();
                $listId = $formModel->getListModel()->getId();
                
                $db->setQuery("SELECT " . $db->qn('id') . ", " . $db->qn('form_id') . " FROM " . $db->qn('#__fabrik_lists') . " WHERE " . $db->qn('db_table_name') . " = '{$this->requestListName}'");
                $r = $db->loadObjectList();
                if (count($r) > 0) {
                    $this->requestListId = $r[0]->id;
                    $this->requestListFormId = $r[0]->form_id;
                }
            } catch (Exception $e) {
            }
        }

        $fieldPrefix = 'req_';
        $this->fieldPrefix = $fieldPrefix;
    }

    /**
     * Sets the value of the requestType attribute
     * 
     * @param       Array       $form           The formData array
     * @param       Array       $delete         The user wants delete record?
     * 
     * @return      Null
     */
    private function setRequestType($form, $delete)
    {
        if(str_contains(array_keys($form)[0], 'easyadmin')) {
            if($form['easyadmin_modal___valIdEl'] != '0') {
                $this->requestType = self::REQUEST_TYPE_EDIT_FIELD;
            } else {
                $this->requestType = self::REQUEST_TYPE_ADD_FIELD;
            }

            $this->easyadmin = true;
            return;
        }

        if ($delete === true) {
            $this->requestType = self::REQUEST_TYPE_DELETE_RECORD;
        } else if (empty($form['rowid'])) {
            $this->requestType = self::REQUEST_TYPE_ADD_RECORD;
        } else {
            $this->requestType = self::REQUEST_TYPE_EDIT_RECORD;
        }
    }

    /**
     * Checks whether the user has permission to perform the action and allows or disallows persisting the data in the database.
     * 
     * @param       Array           $formData           The formData array
     * @param       Boolean         $delete             The user wants delete record?
     * @param       Object          $listModel          The instance of fabrik list model
     * @param       Boolean         $optionsJs          Options form
     * 
     * @return      Boolean
     */
    protected function hasPermission($formData, $delete=false, $listModel=false, $optionsJs=false)
    {
        if (!isset($this->requestType)) {
            $this->setRequestType($formData, $delete);
        }

        if($this->requestType != self::REQUEST_TYPE_DELETE_RECORD && !$this->easyadmin) {
            $listModel = $this->getModel()->getListModel();
        }

        if($this->easyadmin) {
            $listId = $formData['easyadmin_modal___listid'];
            $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
            $listModel->setId($listId);
            $paramsForm = $listModel->getFormModel()->getParams();
            $optionsJs['user']['approve_for_own_records'] = $paramsForm->get('approve_for_own_records');
            $optionsJs['workflow_owner_element'] = $paramsForm->get('workflow_owner_element');
        }

        $params = $listModel->getParams();
        $editOwnElement = NULL;

        $groups = $this->user->getAuthorisedViewLevels();
        $canAdd = in_array($params->get('allow_add'), $groups);
        $canEdit = in_array($params->get('allow_edit_details'), $groups);
        $canDelete = in_array($params->get('allow_delete'), $groups);
        $canRequest = in_array($params->get('allow_request_record'), $groups);

        $allowEditOwn = $params->get('allow_edit_details2');

        if (isset($allowEditOwn)) {
            $allowEditOwn = $this->listName . "___" . str_replace($this->listName . '.', "", $allowEditOwn);
            $ownElement = $formData[$allowEditOwn];

            if (is_array($ownElement)) {
                $ownElement = $ownElement[0];
            }
            if ($this->user->id == $ownElement) {
                $canEdit = true;
            }
        }

        if ($optionsJs != false) {
            $approve_for_own_records =  $optionsJs['user']['approve_for_own_records'];
            $owner_element_id = $optionsJs['workflow_owner_element'];
        } else {
            $approve_for_own_records = $this->params->get('approve_for_own_records');
            $owner_element_id = $this->params->get('workflow_owner_element');
        }

        $owner_element = $listModel->getElements('id');
        $formModel = $listModel->getFormModel();
        $owner_element_name = $owner_element[$owner_element_id]->element->name;

        $table_name = $formModel->getTableName();

        if ($approve_for_own_records == 1) {
            if ($this->user->id == $formData[$table_name . '___' . $owner_element_name][0] || $this->user->id == $formData[$table_name . '___' . $owner_element_name]) {
                $canEdit = true;
                $canDelete = true;
            }
            $canAdd = true;
        }

        switch ($this->requestType) {
            case self::REQUEST_TYPE_ADD_RECORD:
            case self::REQUEST_TYPE_ADD_FIELD:
                if (!$canAdd) {
                    return false;
                }
                break;
            case self::REQUEST_TYPE_EDIT_RECORD:
            case self::REQUEST_TYPE_EDIT_FIELD:
                if (!$canEdit) {
                    return false;
                }
                break;
            case self::REQUEST_TYPE_DELETE_RECORD:
                if (!$canDelete) {
                    return false;
                }
                break;
        }

        return true;
    }

    /**
     * This method verify if the user can make requests
     * 
     * @return      Boolean
     */
    protected function canRequest()
    {
        $app = Factory::getApplication();

        $listModel = $this->getModel()->getListModel();
        $groups = $app->getIdentity()->getAuthorisedViewLevels();
        
        return in_array($listModel->getParams()->get('allow_request_record'), $groups);
    }

    /**
     * Checks which requests the user can see.
     * 
     * @param       String      $allow_review_request       View level to review requests
     * 
     * @return      String
     */
    protected function canViewRequests($allow_review_request='')
    {
        $app = Factory::getApplication();
        
        $groups = $app->getIdentity()->getAuthorisedViewLevels();
        $canView = 'only_own';
        
        if ($this->user->authorise('core.admin')) {
            $canView = 'all';
        } else if (in_array($this->getParams()->get('allow_review_request'), $groups)) {
            $canView = 'all';
        } else if (in_array($allow_review_request, $groups)) {
            $canView = 'all';
        }

        return $canView;
    }

    /**
     * Checks if the user can approve requests
     * 
     * @return      Boolean
     */
    protected function canApproveRequests()
    {
        $app = Factory::getApplication();
        $groups = $app->getIdentity()->getAuthorisedViewLevels();

        if ($this->user->authorise('core.admin')) {
            return true;
        } else if (in_array($this->getParams()->get('allow_review_request'), $groups)) {
            return true;
        }

        return false;
    }

    /**
     * This method check if the add button is set
     * 
     * @return      Null
     */
    protected function checkAddRequestButton()
    {
        $listModel = $this->getModel()->getListModel();

        $_REQUEST['workflow']['showAddRequest'] = !$listModel->canAdd() && $this->canRequest();
        $_REQUEST['workflow']['addRequestLink'] = $this->getFriendlyUrl($listModel->getId(), 'form', $listModel->getFormModel()->getId()) . '?wfl_action=request';
        $_REQUEST['workflow']['listLinkUrl'] = $this->getFriendlyUrl($listModel->getId(), 'list');
        $_REQUEST['workflow']['requestLabel'] = Text::_('PLG_FORM_WORKFLOW_BUTTON_NEW_REQUEST');
        $_REQUEST['workflow']['eventsButton'] = Text::_('PLG_FORM_WORKFLOW_BUTTON_EVENTS');
    }

    /**
     * This method provide the friendly url
     * 
     * @param       Int            $id              Id of the record
     * @param       String         $view            List, form or detail view 
     * @param       Int            $idForm          Id of the form 
     * 
     * @return      String
     * 
     * @since       v4.1
     */
    private function getFriendlyUrl($id, $view, $idForm=0)
    {
        $app = Factory::getApplication();
        $menu = $app->getMenu();

        $menuLinked = $menu->getItems('link', "index.php?option=com_fabrik&view=list&listid=$id", true);
        $alias = '/' . $menuLinked->alias;
        $alias .= $view == 'form' ? "/form/$idForm" : '';

        return $alias;
    }

    /**
     * This method check if the show events button is set
     * 
     * @return      Null
     */
    protected function checkEventsButton()
    {
        $showEventsButton = true;

        if (!$this->countRequests()) {
            $showEventsButton = false;
        }

        $_REQUEST['workflow']['showEventsButton'] = $showEventsButton;
    }

    /**
     * Add the other log fields along with the form data
     * 
     * @param       Array           $formData               The full formData array
     * @param       Boolean         $hasPermission          User has permission or not?
     * 
     * @return      Array
     */
    protected function getFormDataToLog($formData, $hasPermission=false)
    {
        $fieldPrefix = $this->fieldPrefix;

        if ($this->requestType == self::REQUEST_TYPE_EDIT_RECORD || ($hasPermission && !$this->isReview)) {
            $rowid = $formData["rowid"] ? $formData["rowid"] : $formData[0]["rowid"];
            $formData[$fieldPrefix . "record_id"] = $rowid;
        }

        if($this->requestType == self::REQUEST_TYPE_EDIT_FIELD) {
            $formData[$fieldPrefix . "record_id"] = $formData['easyadmin_modal___valIdEl'];
        }

        $formData[$fieldPrefix . "status"] = ($hasPermission ? 'pre-approved' : 'verify');
        $formData[$fieldPrefix . "user_id"] = $this->user->id ?: null;
        $formData[$fieldPrefix . "request_type_id"] = $this->requestType;
        $formData[$fieldPrefix . "created_date"] = date('Y-m-d H:i:s');
        
        if (is_array($formData["owner_id"])) {
            $formData[$fieldPrefix . "owner_id"] =  array_pop(array_reverse($formData["owner_id"]));
        } else {
            $formData[$fieldPrefix . "owner_id"] = $formData["owner_id"];
        }

        $formData[$fieldPrefix . "list_id"] = $this->listId;
        unset($formData["owner_id"]);

        return $formData;
    }

    /**
     * 
     * 
     * @param       Array           $formData           The formData array
     * @param       Int             $userId             The id of the user
     * @param       Int             $ownerId            The id of the owner user
     * @param       String          $date_time          The actual date time
     * 
     * @return      Array
     */
    protected function createLogPreApproved($formData, $userId, $ownerId, $date_time)
    {
        $newFormData = Array();
        $fieldPrefix = $this->fieldPrefix;

        $newFormData[$fieldPrefix . "status"] = 'pre-approved';
        $newFormData[$fieldPrefix . "user_id"] = $userId;
        $newFormData[$fieldPrefix . "request_type_id"] = 1;
        $newFormData[$fieldPrefix . "created_date"] = $date_time;
        $newFormData[$fieldPrefix . "list_id"] = $this->listId;
        $newFormData[$fieldPrefix . "owner_id"] = $ownerId;
        $newFormData[$fieldPrefix . "description"] = Text::_("PLG_FORM_WORKFLOW_COMMENT_CREATED_BY");
        $newFormData[$fieldPrefix . "comment"] = Text::_("PLG_FORM_WORKFLOW_COMMENT_CREATED_BY");
        $rowid = $formData["rowid"] ? $formData["rowid"] : $formData[0]["rowid"];
        $newFormData[$fieldPrefix . "record_id"] = $rowid;

        return $newFormData;
    }

    /**
     * This method get element models from the list
     * 
     * @return      Array
     */
    protected function getListElementModels()
    {
        $elements = Array();
        $formModel = $this->getModel();
        $groups = $formModel->getGroupsHiarachy();

        foreach ($groups as $groupModel) {
            $elementModels = $groupModel->elements;
            foreach ($elementModels as $elementModel) {
                $elements[] = $elementModel;
            }
        }

        return $elements;
    }

    /**
     * Create or update log table
     * 
     * @return      Boolean
     */
    protected function createOrUpdateLogTable()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            // Default log columns
            $clabelsCreateDb = array();
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'id') . " INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'user_id') . " INT(11) NOT NULL";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'field_id') . " INT(11) DEFAULT NULL";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'owner_id') . " INT(11) DEFAULT NULL";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'created_date') . " datetime NOT NULL";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'revision_date') . " datetime DEFAULT NULL";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'reviewer_id') . " INT(11) DEFAULT NULL";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'status') . " varchar(255) NOT NULL";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'request_type_id') . " INT(11) NOT NULL";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'description') . " TEXT";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'comment') . " TEXT";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'approval') . " TEXT";
            $clabelsCreateDb[] = $db->qn($this->fieldPrefix . 'record_id') . " INT(11) DEFAULT NULL";

            // Get list columns
            $elementModels = $this->getListElementModels();
            $defaultTableColumns = array();
            foreach ($elementModels as $elementModel) {
                $element = $elementModel->getElement();
                $field_desc = $elementModel->getFieldDescription();

                if ($element->primary_key) {
                    $field_desc = str_replace(' AUTO_INCREMENT', '', $field_desc);
                }

                $clabelsCreateDb[] = $db->qn($element->name) . ' ' . $field_desc;
                $defaultTableColumns[$element->name] = $db->qn($element->name) . ' ' . $field_desc;
            }

            // Check if request table exists
            $db->setQuery("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $this->requestListName . "'");
            $request_table_exists = (bool) $db->loadResult();

            if (!$request_table_exists) {
                $clabels_createdb = implode(", ", $clabelsCreateDb);
                $create_custom_table = "CREATE TABLE IF NOT EXISTS " . $db->qn($this->requestListName) . " ($clabels_createdb);";
                $db->setQuery($create_custom_table);
                $db->execute();
            } else {
                // check if default list and request list fields are different
                $this->updateLogTable($defaultTableColumns);
            }
        } catch (Exception $e) {
            die($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Method that update log table
     * 
     * @param       Array       $defaultTableColumns        xxxxxxxxxxxxxxxxxxxx
     * 
     * @return      Null
     */
    private function updateLogTable($defaultTableColumns)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery("DESCRIBE `{$this->requestListName}`;");
        $columns = $db->loadObjectList();

        $requestTableColumns = Array();
        $clabelsCreateDb = Array();

        foreach ($columns as $column) {
            if (stripos($column->Field, $this->fieldPrefix) !== false) {
                continue;
            }

            // Checks if the column is not present in the main table
            $requestTableColumns[$column->Field] = $column->Field;
            if (!key_exists($column->Field, $defaultTableColumns)) {
                $clabelsCreateDb[] = "DROP COLUMN " . $db->qn($column->Field);
            }
        }

        // Checks if the column is not already present in the request table
        foreach ($defaultTableColumns as $column => $desc) {
            if (!in_array($column, $requestTableColumns)) {
                $clabelsCreateDb[] = "ADD COLUMN " . $desc;
            }
        }

        if (count($clabelsCreateDb) > 0) {
            $clabels_createdb = implode(", ", $clabelsCreateDb);
            $create_custom_table = "ALTER TABLE " . $db->qn($this->requestListName) . " $clabels_createdb;";
            $db->setQuery($create_custom_table);
            $db->execute();
        }
    }

    /**
     * This method build the email to send to responsible users
     * 
     * @param       Array       $request        The log data
     * 
     * @return      Null
     */
    private function enviarEmailRequest($request=null)
    {
        jimport('joomla.access.access');

        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
        $listModel->setId($request['listid']);

        $request_type =  $this->requestTypes[$request['req_request_type_id']];
        $request_user = $this->user;
        $owner_user = !empty($request['owner_id']) ? Factory::getUser($request['owner_id']) : null;

        $emailTo = array($request_user->email);
        if ($owner_user && $owner_user->id != $request_user->id) {
            $emailTo[] = $owner_user->email;
        }

        $reviewers_id = $this->getReviewers();
        foreach ($reviewers_id as $id) {
            $userModel = Factory::getUser($id);
            $emailTo[] = $userModel->email;
        }

        $link = URI::root() . "index.php" . $listModel->getTableAction();
        $subject = Text::sprintf('PLG_FORM_WORKFLOW_EMAIL_REQUEST_SUBJECT', $this->listName) . " :: " . $this->config->get('sitename');
        $message = Text::sprintf('PLG_FORM_WORKFLOW_EMAIL_REQUEST_BODY', $request_type, $this->listName, $link);

        $this->enviarEmail($emailTo, $subject, $message);
    }

    /**
     * 
     * @param       Array       $request            The log data
     * @param       Int         $record_id          xxxxxxxxxxxxxxxxxxx
     * 
     * @return      Null
     */
    private function enviarEmailRequestApproval($request, $record_id)
    {
        $app = Factory::getApplication();
        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');

        $owner_user_email = !empty($request["req_user_email"]) ? $request["req_user_email"] : null;

        $emailTo = Array();
        if ($owner_user_email) {
            $emailTo[] = $owner_user_email;
        }

        $list_id = $request["req_list_id"];
        $listModel->setId($list_id);

        $list_label = $listModel->getLabel();
        $user_name = $request["req_user_name"];
        $request_type = $request["req_request_type_id"];
        $request_status = $request['req_approval'] === '1' ? Text::_("PLG_FORM_WORKFLOW_APPROVED_F") : Text::_("PLG_FORM_WORKFLOW_NOT_APPROVED_F");

        $menu = $app->getMenu();
        $menuLinked = $menu->getItems('link', "index.php?option=com_fabrik&view=list&listid=$list_id", true);
        $alias = $menuLinked->alias;
        $link = URI::root() . $alias;

        switch ($request_type) {
            case '1':
                $request_type = Text::_("PLG_FORM_WORKFLOW_ADD_RECORD_EMAIL");
                break;
            case '2':
                $request_type = Text::_("PLG_FORM_WORKFLOW_EDIT_FIELD_VALUE_EMAIL");
                break;
            case '3':
                $request_type = Text::_("PLG_FORM_WORKFLOW_DELETE_RECORD_EMAIL");
                break;
            
            case '4':
                $request_type = Text::_("PLG_FORM_WORKFLOW_ADD_FIELD");
                break;
            
            case '5':
                $request_type = Text::_("PLG_FORM_WORKFLOW_EDIT_FIELD");
                break;
        }

        if(in_array($request_type, ['1', '2', '3'])) {
            $link .=  $listModel->viewDetailsLink($record_id) . $record_id;
        }

        $subject = Text::sprintf('PLG_FORM_WORKFLOW_EMAIL_REQUEST_APPROVAL_SUBJECT', $list_label) . " :: " . $this->config->get('sitename');
        $message = Text::sprintf('PLG_FORM_WORKFLOW_EMAIL_REQUEST_APPROVAL_BODY', $user_name, $request_type, $list_label, $request_status, $link);

        $this->enviarEmail($emailTo, $subject, $message);
    }

    /**
     * This method send the emails needed
     * 
     * @param       Array       $emailTo        List of emails to send
     * @param       String      $subject        The subject of the email
     * @param       String      $message        The message od the email
     * 
     * @return      Null
     */
    private function enviarEmail($emailTo, $subject, $message)
    {
        jimport('joomla.mail.helper');

        $emailFrom = $this->config->get('mailfrom');
        $emailFromName = $this->config->get('fromname', $emailFrom);

        foreach ($emailTo as $email) {
            $email = trim($email);
            if (empty($email)) {
                continue;
            }

            if (FabrikWorker::isEmail($email)) {
                $mail = Factory::getMailer();
                $res = Fabrik\Helpers\Worker::sendMail($emailFrom, $emailFromName, $email, $subject, $message, true);
                if ($res !== true) {
                    $this->app->enqueueMessage(Text::sprintf('PLG_FORM_WORKFLOW_DID_NOT_SEND_EMAIL', $email), 'notice');
                }
            } else {
                $this->app->enqueueMessage(Text::sprintf('PLG_FORM_WORKFLOW_DID_NOT_SEND_EMAIL_INVALID_ADDRESS', $email));
            }
        }
    }

    /**
     * Defines if the list is a request list or not
     * 
     * @return      Null
     */
    private function checkIsRequestList()
    {
        $this->isRequestList = (stripos($this->listName, '_request') !== false);
    }

    /**
     * Returns if the list is a request list or not
     * 
     * @return      Boolean
     */
    private function isRequestList()
    {
        return $this->isRequestList;
    }

    /**
     * 
     * 
     * @param       Int         $rowId      xxxxxxxxxxxxxxxx
     * 
     * @return      Object
     */
    private function getRowData($rowId)
    {
        $myDb = Factory::getContainer()->get('DatabaseDriver');
        
        $query = $myDb->getQuery(true);
        $query->select("*")
            ->from($this->listName)
            ->where("{$this->fieldPrefix}id = " . $myDb->q($rowId));
        $myDb->setQuery($query);
        $row = $myDb->loadAssoc();

        return $row;
    }

    /**
     * Trigger called when a row is deleted.
     * 
     * @return      Null
     */
    public function onDeleteRow()
    {
        $app = Factory::getApplication();
        JModelLegacy::addIncludePath(JPATH_SITE . '/components/com_fabrik/models');
        $fabrikModel = JModelLegacy::getInstance('List', 'FabrikFEModel');

        $rowId = $app->input->getInt('rowId');
        $listId = $app->input->getInt('listId');

        $fabrikModel->setId($listId);
        $ok = $fabrikModel->deleteRows($rowId);
    }

    /**
     * This method override delete action default
     * 
     * @return      Null
     */
    public function onReportAbuse()
    {
        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
        $usuario = &Factory::getApplication()->getIdentity();
        $app = Factory::getApplication();
        $date = Factory::getDate();

        $listRowIds = $_REQUEST['listRowIds'];
        $optionsJs = $_REQUEST['options'];

        $listId = explode(":", $listRowIds)[0];
        $rowId = explode(":", $listRowIds)[1];
        $listModel->setId($listId);
        $row = $listModel->getRow($rowId);
        $formData = json_decode(json_encode($row), true);
        $hasPermission = $this->hasPermission($formData, true, $listModel, $optionsJs);

        $owner_element_id = $optionsJs['workflow_owner_element'];
        $owner_element = $listModel->getElements('id');
        $owner_element_name = $owner_element[$owner_element_id]->element->name;

        $formModel = $listModel->getFormModel();
        $table_name = $formModel->getTableName();

        $pk = $listModel->getPrimaryKeyAndExtra();
        $id_raw = $formData[$table_name . '___' . $pk[0]['colname'] . '_raw'];
        $owner_id = $formData[$table_name . '___' . $owner_element_name . '_raw'];

        $data['req_id'] = '';
        $data['req_request_type_id'] = '3';
        $data['req_user_id'] = $usuario->get('id');
        $data['req_field_id'] = '';
        $data['req_created_date'] = $date->toSQL();
        $data['req_status'] = 'verify';
        $data['req_owner_id'] = $owner_id;
        $data['req_reviewer_id'] = '';
        $data['req_revision_date'] = '';
        $data['req_description'] = '';
        $data['req_comment'] = '';
        $data['req_record_id'] = $rowId;
        $data['req_approval'] = '';
        $data['req_file'] = '';
        $data['req_list_id'] = $listId;
        $data['form_data'] = '';
        $this->fieldPrefix = 'req_';

        if (!$hasPermission) {
            $this->saveLog($data);
        } else {
            $formData['rowid'] = $rowId;
            $formData["owner_id_raw"] = $owner_id;
            $this->listId = $optionsJs['listId'];
            $this->createLog($formData, $hasPermission);
            $listModel->deleteRows($rowId);
        }
    }

    /**
     * This method verify if the user can delete the record
     * 
     * @return      Boolean
     */
    public function onBeforeGetData()
    {
        $this->init();

        $listModel = $this->getModel()->getListModel();
        $listModel->setId($this->listId);

        $row = $listModel->getRow($this->app->input->get('rowid'));
        $can_delete = in_array($this->user->id, $this->getReviewers($row));
        $_REQUEST['workflow_can_delete_upload'] = $can_delete;
        unset($listModel);

        return true;
    }

    /**
	 * Render the element admin settings
	 *
	 * @param       Array       $data               Admin data
	 * @param       Int         $repeatCounter      Repeat plugin counter
	 * @param       String      $mode               How the fieldsets should be rendered currently support 'nav-tabs'
	 *
	 * @return      String
	 */
    public function onRenderAdminSettings($data = array(), $repeatCounter = null, $mode = null)
    {
        $this->install();

        return parent::onRenderAdminSettings($data, $repeatCounter, $mode);
    }

    /**
     * This method only call to main method that verify if the user has the permission needed
     * 
     * @return      Boolean
     * 
     * @since       version 4.1
     */
    public function onHasPermission()
    {
        $permission = $this->hasPermission($_POST);

        echo json_encode($permission);
    }

    /**
     * This method only call to main method that create the log on table
     * 
     * @return      Boolean
     * 
     * @since       version 4.1
     */
    public function onCreateLog()
    {
        $response = new stdClass();
        if (!isset($this->requestType)) {
            $this->setRequestType($_POST, false);
        }
        
        $response->error = false;
        switch ($this->requestType) {
            case self::REQUEST_TYPE_ADD_FIELD:
                $response->message = Text::_("PLG_FORM_WORKFLOW_FIELD_CREATE_SUCESS_MESSAGE");
                break;
            
            case self::REQUEST_TYPE_EDIT_FIELD:
                $response->message = Text::_("PLG_FORM_WORKFLOW_FIELD_EDIT_SUCESS_MESSAGE");
                break;
        }

        try {
            $create = $this->createLog($_POST, (bool) $_POST['hasPermission']);
        } catch (\Throwable $th) {
            $response->error = true;
            $response->message = Text::_("PLG_FORM_WORKFLOW_PROCESS_LOG_FAIL");
            $response->msgError = FabrikHelperHTML::isDebug() ? $th->getMessage() : $response->msg;
        }
        
        echo json_encode($response);
    }

    /**
     * This method render the fields to modal form
     * 
     * @return      Null
     * 
     * @since       version 4.2
     */
    public function onBuildForm()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $filter = JFilterInput::getInstance();
        $app = Factory::getApplication();

        $response = new stdClass();
        $response->error = false;

        $input = $app->input;
        $request = $filter->clean($input->getString('data'), 'array');
        $mod = $input->getString('mod');
        $returnFields = $mod == 'formRequest' ? 1 : 0;

        try {
            $configFields = $this->configFields($request, $mod);
            $this->setTextElements($els, $configFields['text'], $request, $mod);
            $this->setTextareaElements($els, $configFields['textarea'], $request, $mod);
            $this->setYesnoElements($els, $configFields['yesno'], $request, $mod);
            $response->fields = $this->setUpBodyElements($els, $returnFields);
        } catch (\Throwable $th) {
            $response->error = true;
            $response->message = Text::_("PLG_FORM_WORKFLOW_ERROR_BUILD_FORM");
        }
        
        echo json_encode($response);
    }

    /**
     * This method separate the fields by type
     * 
     * @param       Array       $data       Array with the fields values
     * @param       String      $mod        Which form we need to build?
     * 
     * @return      Array
     * 
     * @since       v4.2
     */
    private function configFields($data, $mod) 
    {
        $app = Factory::getApplication();
        $model = $app->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');

        $input = $app->input;
        $keys = array_keys($data);
        $configFields = Array();
        $ignore = Array();
        
        switch ($mod) {
            case 'requestData':
                $ignore = ['req_request_type_id', 'req_id', 'req_revision_date', 'req_list_id', 'req_user_email', 'req_reviewers_votes', 'req_owner_id', 'req_user_id', 'req_file', 'form_data', 'req_reviewer_name'];
                break;
            
            case 'formRequest':
                $configFields = Array(
                    'textarea' => Array('commentTextArea'),
                    'yesno' => Array('yesnooptions', 'voteoptions')
                );
                return $configFields;
        }

        foreach ($keys as $field) {
            if(in_array($field, $ignore)) {
                continue;
            }

            switch ($mod) {
                case 'requestData':
                    $configFields['text'][] = $field;
                    break;
            }
        }
        
        return $configFields;
    }

    /**
	 * Setter method to text elements
	 *
	 * @param   	Array 		    $els	    		Reference to text elements name
	 * @param   	Array 		    $elements			Text elements name
	 * @param		Array		    $data		        Form data for each element
     * @param       String          $mod                Which form we need to build?
	 *
	 * @return  	Null
	 * 
	 * @since 		version 4.2
	 */
	private function setTextElements(&$els, $elements, $data, $mod) 
	{
        foreach ($elements as $id) {
            $value = $data[$id];
            $id = rtrim($id, '_raw');
            $dEl = new stdClass;

            if(empty($value)) continue;

            switch ($id) {
                case 'req_status':
                    $value = $this->statusLista[$value];
                    break;

                case 'req_request_type_name':
                    $value = Text::_("PLG_FORM_WORKFLOW_" . strtoupper($value));
                    break;
            }

            // Options to set up the element
            $dEl->attributes = Array(
                'type' => 'text',
                'id' => $id,
                'name' => $id,
                'size' => 0,
                'maxlength' => '255',
                'class' => 'form-control fabrikinput inputbox text',
                'value' => $value,
                'disabled' => 'disabled'
            );

            $classField = new PlgFabrik_ElementField($this->subject);
            $els[$id]['objField'] = $classField->getLayout('form');
            $els[$id]['objLabel'] = FabrikHelperHTML::getLayout('fabrik-element-label', [COM_FABRIK_BASE . 'components/com_fabrik/layouts/element']);

            $els[$id]['dataLabel'] = $this->getDataLabel(
                $id,
                Text::_('PLG_FORM_WORKFLOW_' . strtoupper($id) . '_LABEL'), 
                Text::_('PLG_FORM_WORKFLOW_' . strtoupper($id) . '_DESC'), 
            );
            $els[$id]['dataField'] = $dEl;
        }
	}

    /**
	 * Setter method to textarea elements
	 *
	 * @param   	Array 		    $els	    		Reference to textarea elements name
	 * @param   	Array 		    $elements			Textarea elements name
	 * @param		Array		    $data		        Form data for each element
     * @param       String          $mod                Which form we need to build?
	 *
	 * @return  	Null
	 * 
	 * @since 		version 4.2
	 */
	private function setTextareaElements(&$els, $elements, $data, $mod) 
	{
        foreach ($elements as $id) {
            $value = $data[$id];
            $dEl = new stdClass;

            switch ($id) {
                case 'commentTextArea':
                    $value = $data['req_comment'];
                    $label = Text::_("PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_COMMENT_LABEL");
                    $desc = Text::_("PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_COMMENT_LABEL");
                    break;
            }

            // Options to set up the element
            $dEl->attributes = Array(
                'id' => $id,
                'name' => $id,
                'size' => 0,
                'maxlength' => '255',
                'class' => 'inputbox col-sm-12',
                'value' => $value,
                'cols' => 60,
                'rows' => 3
            );

            $classField = new PlgFabrik_ElementTextarea($this->subject);
            $els[$id]['objField'] = $classField->getLayout('form');
            $els[$id]['objLabel'] = FabrikHelperHTML::getLayout('fabrik-element-label', [COM_FABRIK_BASE . 'components/com_fabrik/layouts/element']);

            $els[$id]['dataLabel'] = $this->getDataLabel(
                $id, 
                $label,
                $desc,
            );
            $els[$id]['dataField'] = $dEl;
        }
	}

    /**
	 * Setter method to yesno elements
	 *
	 * @param   	Array 		    $els	    		Reference to yesno elements name
	 * @param   	Array 		    $elements			Yesno elements name
	 * @param		Array		    $data		        Form data for each element
     * @param       String          $mod                Which form we need to build?
	 *
	 * @return  	Null
	 * 
	 * @since 		version 4.2
	 */
	private function setYesnoElements(&$els, $elements, $data, $mod) 
	{
        $app = Factory::getApplication();

		$model = $app->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $model->setId($data['req_list_id']);

        foreach ($elements as $id) {
            $dEl = new stdClass;

            switch ($id) {
                case 'yesnooptions':
                    $label = Text::_("PLG_FORM_WORKFLOW_LABEL_REQUEST_APROVAL");
                    $desc = Text::_("PLG_FORM_WORKFLOW_LABEL_REQUEST_APROVAL");
                    break;
                
                case 'voteoptions':
                    $params = $model->getFormModel()->getParams();

                    $parcialApproved = empty($data['req_vote_approve']) ? 0 : $data['req_vote_approve'];
                    $parcialDisapproved = empty($data['req_vote_disapprove']) ? 0 : $data['req_vote_disapprove'];
                    $votesNeededApprove = $params->get('workflow_votes_to_approve');
                    $votesNeededDisapprove = $params->get('workflow_votes_to_disapprove');
                    $desc = Text::_("PLG_FORM_WORKFLOW_LABEL_REQUEST_APROVAL");
                    $label = Text::_("PLG_FORM_WORKFLOW_PARTIAL_VOTES") 
                        . '<br>' 
                        . Text::_("PLG_FORM_WORKFLOW_LABEL_REQUEST_APPROVED") 
                        . $parcialApproved 
                        . ' (' . $votesNeededApprove . ' '
                        . Text::_("PLG_FORM_WORKFLOW_NEEDED_VOTES") . ')'
                        . '<br>' 
                        . Text::_("PLG_FORM_WORKFLOW_LABEL_REQUEST_DISAPPROVED") 
                        . $parcialDisapproved
                        . ' (' . $votesNeededDisapprove . ' '
                        . Text::_("PLG_FORM_WORKFLOW_NEEDED_VOTES") . ')'
                        . '<br><br>'
                        . Text::_('PLG_FORM_WORKFLOW_LABEL_REQUEST_APROVAL');
                    break;
            }

            // Options to set up the element
            $opts = Array(
                Text::_('JNO'), 
                Text::_('JYES')
            );

            $els[$id]['objField'] = new FileLayout('joomla.form.field.radio.switcher');
            $els[$id]['objLabel'] = FabrikHelperHTML::getLayout('fabrik-element-label', [COM_FABRIK_BASE . 'components/com_fabrik/layouts/element']);

            $els[$id]['dataLabel'] = $this->getDataLabel(
                $id, 
                $label,
                $desc,
            );
            $els[$id]['dataField'] = Array(
                'value' => 0,
                'options' => $this->optionsElements($opts),
                'name' => $id,
                'id' => $id,
                'class' => 'fbtn-default fabrikinput',
                'dataAttribute' => 'style="margin-bottom: 0px; padding: 0px"',
            );
        }
	}

   	/**
	 * Method that set up the options(labels and values) to elements
	 * Copied by easyadmin plugin
     * 
	 * @param		Array		$opts		Options with value and label
	 * 
	 * @return  	Array
	 * 
	 * @since 		version 4.2
	 */
	private function optionsElements($opts) 
	{
		$qtnTypes = count($opts);
		$x = 0;

		foreach ($opts as $value => $text) {
			$options[$x] = new stdClass();
			$options[$x]->value = $value;
			$options[$x]->text = $text;
			$options[$x]->disabled = false;
			$x++;
		}

		return $options;
	}

    /**
	 * Getting the array of data to construct the elements label
     * Copied by easyadmin plugin
	 *
	 * @param		String			$id					Identity of the element
	 * @param		String			$label				Label of the element
	 * @param		String			$tip				Tip of the element
	 * @param   	Array 			$showOnTypes		When each element must show on each type of elements (Used in js)
	 * @param		Boolean			$fixed				If the element is fixed always or must show and hide depending of the types above
	 * @param		String			$modal				If the element is at list modal or element modal
	 *
	 * @return  	Array
	 * 
	 * @since 		version 4.2
	 */
	private function getDataLabel($id, $label, $tip, $showOnTypes='', $fixed=true, $modal='element') 
	{
		$class = $fixed ?  '' : "modal-$modal type-" . implode(' type-', $showOnTypes);

		$data = Array(
			'canView' => true,
			'id' => $id,
			'canUse' => true,
			'label' => $label,
			'hasLabel' => true,
			'view' => 'form',
			'tipText' => $tip,
			'tipOpts' => (object) ['formTip' => true, 'position' => 'top-left', 'trigger' => 'hover', 'notice' => true],
			'labelClass' =>  "form-label fabrikLabel {$class}",
		);

		return $data;
	}

    /**
	 * Method that set up the elements
	 * Copied by easyadmin plugin
     * 
	 * @param		Int			$return			Choose to string return (0) or array return (1)
	 * 
	 * @return  	String|Array
	 * 
	 * @since 		version 4.2
	 */
	private function setUpBodyElements($elements, $return=0) 
	{
		$layoutBody = $this->getLayout('modal-body');

		$data = new stdClass();
		$data->labelPosition = '0';

		foreach ($elements as $nameElement => $element) {
			$dEl = new stdClass();
			$data->label = $element['objLabel']->render((object) $element['dataLabel']);
			$data->element = isset($element['objField']) ? $element['objField']->render($element['dataField']) : '';
			$data->cssElement = $element['cssElement'];

			switch ($return) {
				case 1:
					$body[$nameElement] = $layoutBody->render($data);
					break;
				
				default:
					$body .= $layoutBody->render($data);
					break;
			}
		}

		return $body;
	}

    /**
     * Install the plugin db tables
     *
     * @return      Null
     */
    public function install()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $sql = "CREATE TABLE IF NOT EXISTS `#__fabrik_requests` (
			`req_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`req_request_type_id` INT(11),
			`req_user_id` INT(11),
			`req_field_id` INT(11),
			`req_created_date` TIMESTAMP,
			`req_owner_id` INT(11),
			`req_reviewer_id` INT(11),
			`req_revision_date` TIMESTAMP,
			`req_status` VARCHAR(255),
			`req_description` TEXT,
			`req_comment` TEXT,
			`req_record_id` INT(11),
			`req_approval` TEXT,
			`req_file` TEXT,
			`req_list_id` INT(11),
			`form_data` TEXT,
            `req_vote_approve` INT(11),
			`req_vote_disapprove` INT(11),
			`req_reviewers_votes` TEXT
            )";

        $db->setQuery($sql)->execute();

        $sqlType = "CREATE TABLE IF NOT EXISTS `workflow_request_type` (
			`id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`name` VARCHAR(20) DEFAULT NULL
        )";

        $db->setQuery($sqlType)->execute();

        $select = "SELECT * FROM `workflow_request_type`";
        $db->setQuery($select)->execute();
        $result = $db->loadObjectList();

        if (empty($result)) {
            $sqls = [
                "INSERT INTO `workflow_request_type` VALUES (1,'add_record'), (2,'edit_field_value'), (3,'delete_record'), (4,'add_field'), (5,'edit_field');",
            ];

            foreach ($sqls as $sql) {
                $db->setQuery($sql)->execute();
            }
        }
    }

    /**
	 * Setter method to images variable
	 *
	 * @return  	Null
	 * 
	 * @since 		version 4.1
	 */
	public function setImages() 
	{
		$this->images['view'] = FabrikHelperHTML::image('view.png', 'list');
		$this->images['danger'] = FabrikHelperHTML::image('danger.png', 'list');
	}

	/**
	 * Getter method to images variable
	 *
	 * @return  	Object
	 * 
	 * @since 		version 4.1
	 */
	public function getImages() 
	{
		return $this->images;
	}
}