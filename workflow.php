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

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Filesystem\Path;

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

    protected $requests_table_attrs = array(
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

        // Get params to find requests
        $approveOwn = $_REQUEST['approve_for_own_records'];
        $wfl_action = $_REQUEST['wfl_action'];
        $list_id = $_REQUEST['list_id'];
        $user_id = $_REQUEST['user_id'];
        $req_status = $_REQUEST['req_status'];
        $sequence =  $_REQUEST['sequence'];
        $allow_review_request =  $_REQUEST['allow_review_request'];

        // Get DB object to make the query
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        // Create the query
        $query->select('req_id')
            ->select('req_user_id')
            ->select('u_req.name as req_user_name')
            ->select('u_req.email as req_user_email')
            ->select('req_created_date')
            ->select('req_owner_id')
            ->select('u_owner.name as req_owner_name')
            ->select('req_reviewer_id')
            ->select('u_rev.name as req_reviewer_name')
            ->select('req_revision_date')
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
            // Verify if can approve requests for onw requests
            if ($approveOwn) {
                $query->where("(req_user_id = '{$user_id}' OR req_owner_id = '{$user_id}')");
            } else {
                $query->where("req_user_id = '{$user_id}'");
            }
        }

        // Verify if only wants count how many records and return it
        if (isset($_REQUEST['count']) && $_REQUEST['count'] == "1") {
            $db->setQuery($query);
            $db->execute();
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

            $query->select('*')
                ->from("($subQuery) AS results");
            $query->setLimit($_REQUEST['length'], $_REQUEST['start']);
        } else {
            $query->setLimit(5, 0);
        }

        // Set order_by if it exists on request
        if (isset($_REQUEST['order_by'])) {
            $query->order("{$_REQUEST['order_by']} $sequence");
        } else {
            $query->order("req_created_date asc");
        }

        // Executes query on database
        $db->setQuery($query);
        $db->execute();
        $r = $db->loadObjectList();

        // Arranges data per request status
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
        $req_record_id = $_REQUEST['req_record_id'];
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Get the last formData from #__fabrik_requests table
        $query = $db->getQuery(true);
        $query->select($db->qn("form_data"))
            ->from($db->qn("#__fabrik_requests"))
            ->where($db->qn("req_record_id") . ' = ' . "$req_record_id")
            ->where("(`req_status` = 'approved' or `req_status` = 'pre-approved')")
            ->order('req_id desc');
        $db->setQuery($query);

        echo $db->loadResult();
    }

    /**
     * This function gets the files uploaded. Called by AJAX
     * 
     * @return      Null
     */
    public function onGetFileUpload()
    {
        // Filter the request
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');

        // Get the params from requests
        $parent_table_name = $request['parent_table_name'];
        $element_name = $request['element_name'];
        $parent_id = $request['parent_id'];

        // Get DB and query object
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        // Get all images where parent_id is equal to $parent_id
        $query
            ->select($db->qn($element_name) . ' as value')
            ->from($db->qn($parent_table_name . "_repeat_" . $element_name))
            ->where($db->qn("parent_id") . ' = ' . "$parent_id");
        $db->setQuery($query);
        $db->execute();

        $ids = $db->loadObjectList();

        // Encode and return images paths
        echo json_encode($ids);
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
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');

        $req_list_id = $list_id ? $list_id : $request['req_list_id'];

        // Recebe o obj para acessar o DB
        $db = Factory::getContainer()->get('DatabaseDriver');
        $subQuery = $db->getQuery(true);
        // Sub Query to catch the group_id using a list_id
        $subQuery
            ->select($db->qn('b.group_id'))
            ->from($db->qn('#__fabrik_lists', 'a'))
            ->join('INNER', $db->qn('#__fabrik_formgroup', 'b') .
                ' ON ' . $db->qn('a.form_id') . ' = ' . $db->qn('b.form_id'))
            ->where($db->qn('a.id') . ' = ' . $req_list_id);

        // Get all groups to get all elements
        $db->setQuery($subQuery);
        $db->execute();

        $results = $db->loadObjectList();

        // Array to concat all elements groups
        $allElements = array();

        foreach ($results as $group) {
            $query = $db->getQuery(true);
            $group_id = $group->group_id;
            $query
                ->select(array($db->qn('name'), $db->qn('plugin'), $db->qn('params')))
                ->from($db->qn('#__fabrik_elements'))
                ->where($db->qn('group_id') . ' = ' . "$group_id");

            $db->setQuery($query);
            $db->execute();

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
     * 
     * 
     * @return      Null
     */
    public function onGetDatabaseJoinMultipleData()
    {
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');

        $response = new StdClass;

        $parent_table_name = $request['parent_table_name'];
        $element_name = $request['element_name'];
        $parent_id = $request['parent_id'];
        $join_val_column = $request['join_val_column'];
        $join_key_column = $request['join_key_column'];
        $join_db_name = $request['join_db_name'];
        $request_elements_array = json_decode($request['request_elements_array']);

        if (!is_array($request_elements_array)) {
            $request_elements_array = array($request_elements_array);
        }


        // Recebe o obj para acessar o DB
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        // Pega os IDs originais
        $query
            ->select($db->qn($element_name) . ' as value')
            ->from($db->qn($parent_table_name . "_repeat_" . $element_name))
            ->where($db->qn("parent_id") . ' = ' . "$parent_id");

        $db->setQuery($query);
        $db->execute();

        $ids = $db->loadObjectList();

        if (!empty($ids)) {
            // Pega o raw dos ids originais
            $query = $db->getQuery(true);

            $whereClauses = array();

            foreach ($ids as $id) {
                $whereClauses[] = $db->qn($join_key_column) . ' = ' . $db->q($id->value);
            }

            $query
                ->select(array($db->qn($join_val_column) . ' as value', 'id'))
                ->from($db->qn($join_db_name))
                ->where($whereClauses, 'OR');

            $db->setQuery($query);
            $db->execute();
            $results = $db->loadObjectList();

            $response->original = $results;
        } else {
            $response->original = array();
        }

        // get raw from the requested objects
        $query = $db->getQuery(true);
        $whereClauses = array();

        foreach ($request_elements_array as $id) {
            $whereClauses[] = $db->qn($join_key_column) . ' = ' . $db->q($id);
        }
        $query
            ->select(array($db->qn($join_val_column) . ' as value', 'id'))
            ->from($db->qn($join_db_name))
            ->where($whereClauses, 'OR');

        $db->setQuery($query);
        $db->execute();
        $results = $db->loadObjectList();

        $response->request = $results;
        echo json_encode($response);
    }

    /**
     * 
     * 
     * @return      Null
     */
    public function onGetDatabaseJoinSingleData()
    {
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');

        $join_val_column = $request['join_val_column'];
        $join_key_column = $request['join_key_column'];
        $join_db_name = $request['join_db_name'];
        $element_id = $request['element_id'];
        $original_element_id = $request['original_element_id'];

        // Recebe o obj para acessar o DB
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query
            ->select($db->qn($join_val_column) . ' as value')
            ->from($db->qn($join_db_name))
            ->where($db->qn($join_key_column) . ' = ' . "$element_id");

        $db->setQuery($query);
        $db->execute();

        $results = $db->loadObjectList();

        $return = new stdClass;
        $return->new = $results;


        if (!empty($original_element_id)) {
            $query = $db->getQuery(true);

            $query
                ->select($db->qn($join_val_column) . ' as value')
                ->from($db->qn($join_db_name))
                ->where($db->qn($join_key_column) . ' = ' . "$original_element_id");

            $db->setQuery($query);
            $db->execute();
            $results = $db->loadObjectList();
            $return->original = $results;
        }

        echo json_encode($return);
    }

    /**
     * 
     * 
     * 
     * @return      Null
     */
    public function onUploadFileToRequest()
    {
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');

        try {
            if (0 < $_FILES['file']['error']) {
                echo 'Error: ' . $_FILES['file']['error'] . '<br>';
            } else {
                move_uploaded_file($_FILES['file']['tmp_name'], 'uploads/' . $_FILES['file']['name']);
            }
        } catch (Exception $e) {
            echo $e;
        }
    }

    /**
     * 
     * 
     * @return      Null
     */
    public function onGetRequest()
    {
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');
        $req_id = $request['req_id'];
        // Recebe o obj para acessar o DB
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Cria novo obj query
        $query = $db->getQuery(true);

        // // Seleciona identificador e valor
        $query->select(array('a.*', 'b.name as req_user_name', 'c.name as req_owner_name', 'd.name as req_reviewer_id_raw'));

        // Da tabela $join_name
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

        // Aplica a query no obj DB
        $db->setQuery($query);

        // Salva resultado da query em results
        $results = $db->loadObjectList();

        // Codifica $results para JSON
        echo json_encode($results);
    }

    /**
     * 
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
     * Called by AJAX, this method updates the request record on the #__fabrik_requests table
     * 
     * @return      Null
     */
    public function onProcessRequest()
    {
        $fieldsToUpdate = array(
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

        // Catch ajax params
        $requestData = $request['formData'][0];
        $data = $requestData;
        foreach ($requestData as $key => $value) {
            if (!in_array($key, $fieldsToUpdate)) {
                unset($requestData[$key]);
            }
        }

        $usuario = &Factory::getApplication()->getIdentity();

        if ($request["options"]["workflow_approval_by_votes"] == 1) {
            if ($requestData['req_status'] == 'approved' || $requestData['req_status'] == 'not-approved') {
                $requestData['req_revision_date'] = date("Y-m-d H:i:s");
                $requestData['req_approval'] = $requestData['req_status'] === 'approved' ? 1 : 0;
            }
            $requestData['req_reviewers_votes'] .= $usuario->id . ',';
        } else {
            $requestData['req_status'] = $requestData['req_approval'] === '1' ? 'approved' : 'not-approved';
        }

        $requestData['req_vote_approve'] =  $requestData['req_vote_approve'] === '' ? null : $requestData['req_vote_approve'];
        $requestData['req_vote_disapprove'] = $requestData['req_vote_disapprove'] === '' ? null : $requestData['req_vote_disapprove'];

        $db = Factory::getContainer()->get('DatabaseDriver');
        $requestData['req_reviewer_id'] = $requestData['req_reviewer_id'] === '' ? "{$usuario->id}" : $requestData['req_reviewer_id'];

        $obj = (object) $requestData;
        $results = $db->updateObject('#__fabrik_requests', $obj, 'req_id', false);
        $r = $this->saveNotification($requestData);
        $return->response = true;

        // @TODO - SEND MAIL
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
        Text::script('PLG_FORM_WORKFLOW_REQ_OWNER_ID_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_TYPE_ID_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_USER_ID_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_CREATED_DATE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_STATUS_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_RECORD_ID_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_LIST_ID_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_REVIEWER_ID_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_REVISION_DATE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_COMMENT_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_FILE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_LABEL');

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
        Text::script('PLG_FORM_WORKFLOW_NOTE_APPROVED');

        Text::script('PLG_FORM_WORKFLOW_ADD_RECORD');
        Text::script('PLG_FORM_WORKFLOW_EDIT_FIELD_RECORD');
        Text::script('PLG_FORM_WORKFLOW_DELETE_RECORD');
        Text::script('PLG_FORM_WORKFLOW_ADD_FIELD');
        Text::script('PLG_FORM_WORKFLOW_EDIT_FIELD');

        Text::script('PLG_FORM_WORKFLOW_LOG');
        Text::script('PLG_FORM_WORKFLOW_REQUEST_VOTE_APPROVAL_LABEL');
        Text::script('PLG_FORM_WORKFLOW_VOTES_TO_APPROVE_LABEL');
        Text::script('PLG_FORM_WORKFLOW_VOTES_TO_DISAPPROVE_LABEL');
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
    }

    /**
     * Updates the record on the main list
     * 
	 * @param		Array 		$formData		    The formData array
	 * @param		xxxxxx 		$requestData		xxxxxxxxxxxxxxxxxxxxxxxx
	 * @param		xxxxxx 		$sendMail		    xxxxxxxxxxxxxxxxxxxxxxxx
     * 
     * @return      Boolean
     */
    public function saveToMainList($formData, $requestData, $sendMail)
    {
        try {
            $filesElements = array();
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true);
            $subQuery = $db->getQuery(true);

            // Sub Query to catch the group_id using a list_id
            $subQuery
                ->select($db->qn('b.group_id'))
                ->from($db->qn('#__fabrik_lists', 'a'))
                ->join('INNER', $db->qn('#__fabrik_formgroup', 'b') .
                    ' ON ' . $db->qn('a.form_id') . ' = ' . $db->qn('b.form_id'))
                ->where($db->qn('a.id') . ' = ' . $requestData['req_list_id']);

            // Query to catch plugins type from all elements from a group_id
            $query
                ->select(array($db->qn('name'), $db->qn('plugin')))
                ->from($db->qn('#__fabrik_elements'))
                ->where($db->qn('group_id') . ' = ' . "($subQuery)");
            $db->setQuery($query);
            $db->execute();
            $results = $db->loadObjectList();

            $elements = new StdClass;
            $dbJoinOptions = array();
            foreach ($results as $result) {
                $property = $result->name;
                $elements->$property = $result->plugin;
            }
            $listName = $this->getListName($requestData["req_list_id"])->db_table_name;
            $record_id = $requestData["req_record_id"];
            if ($requestData['req_request_type_id'] == 'delete_record') {
                $query->delete($db->qn($listName))
                    ->where(array($db->qn('id') . " = '{$record_id}'"));
            } else {
                $columns = $values = $strValues = array();
                foreach ($formData as $k => $v) {
                    if ($k == 'id') {
                        continue;
                    }
                    if (is_array(json_decode($v)) || $elements->$k == 'fileupload') {
                        switch ($elements->$k) {
                            case 'fileupload':
                                unset($v->is_files);
                                $v->saveToTableName = $listName . "_repeat_" . $k;
                                $filesElements[$k] = $v;
                                break;
                            case 'databasejoin':
                                $options = json_decode($v);
                                $table_name = $listName . '_' . 'repeat' . '_' . $k;
                                $query->select(1)
                                    ->from($table_name);
                                $db->setQuery($query);
                                $db->execute();
                                $results = $db->loadObjectList();
                                if ($results) {
                                    // Delete the older options
                                    $query = $db->getQuery(true);
                                    $query->delete($db->qn($table_name))
                                        ->where(array($db->qn('parent_id') . " = '{$record_id}'"));
                                    $db->setQuery($query);
                                    $db->execute();
                                    // Insert the new options
                                    foreach ($options as $option) {
                                        $object = new StdClass;
                                        $object->table_name = $table_name;
                                        $object->k = $k;
                                        $object->option = $option;
                                        $dbJoinOptions[] = $object;
                                    }
                                } else {
                                    echo 'ERROR';
                                }
                                break;
                            default:
                                $columns[] = $k;
                                $values[] = $db->q($v);
                                $strValues[] = "{$db->qn($k)} = {$db->q($v)}";
                                break;
                        }
                    } else {
                        $columns[] = $k;
                        $values[] = $db->q($v);
                        $strValues[] = "{$db->qn($k)} = {$db->q($v)}";
                    }
                }
                $query = $db->getQuery(true);
                if ($requestData['req_request_type_id'] == "add_record") {
                    $query->insert($db->qn($listName))
                        ->columns($db->qn($columns))
                        ->values(implode(',', $values));
                } else  if ($requestData['req_request_type_id'] == "edif_field_value") {
                    $query->update($db->qn($listName))
                        ->set($strValues)
                        ->where(array($db->qn('id') . " = {$record_id}"));
                }
            }
            $db->setQuery($query);

            $db->execute();

            $query = $db->getQuery(true);
            $query->select('id')
                ->from($listName)
                ->where("date_time = '{$formData->date_time}'")
                ->where("owner_id = '{$formData->owner_id}'");

            $db->setQuery($query);
            $db->execute();
            $results = $db->loadObjectList();
            $record_id = ($results[0])->id;

            foreach ($dbJoinOptions as $key => $object) {
                $query = $db->getQuery(true);
                $columns = array('parent_id', $object->k);
                $values = array($record_id, $object->option);
                $query
                    ->insert($db->qn($object->table_name))
                    ->columns($db->qn($columns))
                    ->values(implode(',', $values));
                $db->setQuery($query);
                $db->execute();
            }
            if (!empty($filesElements)) {
                foreach ($filesElements as $id => $files) {
                    $saveToTableName = $files->saveToTableName;
                    unset($files->saveToTableName);
                    $filesArray = (array) $files;
                    foreach ($filesArray as $chave => $link) {
                        $query = $db->getQuery(true);

                        $columns = array('parent_id', 'imagem');
                        $values = array($db->q($record_id), $db->q($link));

                        $query
                            ->insert($db->qn($saveToTableName))
                            ->columns($db->qn($columns))
                            ->values(implode(',', $values));

                        $db->setQuery($query);
                        $db->execute();
                    }
                }
            }
            if (!empty($sendMail) && $sendMail) {
                $this->enviarEmailRequestApproval($requestData, $record_id);
            }

            return true;
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * 
     * 
     * @param       Int         $listId         The list id
     * 
     * @return      Object
     */
    public function getListName($listId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);
        $query->select($db->qn('db_table_name'));
        $query->from($db->qn('#__fabrik_lists'));
        $query->where($db->qn('id') . ' = ' . $listId);
        $db->setQuery($query);
        $results = $db->loadObjectList();
        
        return $results[0];
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
        $options = json_encode($options);
        $jsFiles = Array();
        $jsFiles['Fabrik'] = 'media/com_fabrik/js/fabrik.js';
        $jsFiles['FabrikWorkflow'] = 'plugins/fabrik_form/workflow/workflow.js';
        $script = "var workflow = new FabrikWorkflow($options);";
        FabrikHelperHTML::script($jsFiles, $script);
    }

    /**
     * This method pass the list of requests to the view, it is replacing this->processLog()
     * 
     * @return      Null
     */
    public function listRequests()
    {
        $approveOwn = (int)$this->params->get('approve_for_own_records');
        $this->loadJs();
        $wfl_action = filter_input(INPUT_GET, 'wfl_action', FILTER_SANITIZE_STRING);
        $req_status = filter_input(INPUT_GET, 'wfl_status', FILTER_SANITIZE_STRING);
        $req_status = $req_status ?: 'verify';
        $_REQUEST['wfl_action'] = $wfl_action;
        $_REQUEST['wfl_status'] = $req_status;

        //headings
        $headings = array(
            'req_request_type_name' => Text::_('PLG_FORM_WORKFLOW_REQUEST_TYPE_ID_LABEL'),
            'req_user_name' => Text::_('PLG_FORM_WORKFLOW_REQUEST_USER_ID_LABEL'),
            'req_created_date' => Text::_('PLG_FORM_WORKFLOW_REQUEST_CREATED_DATE_LABEL'),
            'req_owner_name' => Text::_('PLG_FORM_WORKFLOW_REQ_OWNER_ID_LABEL'),
            'req_reviewer_name' => Text::_('PLG_FORM_WORKFLOW_REQUEST_REVIEWER_ID_LABEL'),
            'req_revision_date' => Text::_('PLG_FORM_WORKFLOW_REQUEST_REVISION_DATE_LABEL'),
            'req_status' => Text::_('PLG_FORM_WORKFLOW_REQUEST_STATUS_LABEL'),
            'req_record_id' => Text::_('PLG_FORM_WORKFLOW_REQUEST_RECORD_ID_LABEL'),
            'req_approval' => Text::_('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_LABEL'),
            'view' => Text::_('PLG_FORM_WORKFLOW_REQUEST_VIEW_LABEL')
        );

        //grupos
        $statusLista = array(
            'verify' => 'Verify',
            'approved' => 'Approved',
            'not-approved' => 'Not Approved',
            'pre-approved' => 'Pre-Approved',
        );

        //data
        $db = Factory::getContainer()->get('DatabaseDriver');
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
        // @TODO Verificar se é pra aprovar os proprios registros
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
        $db->execute();
        $r = $db->loadObjectList();

        //organiza os dados
        $dados = array();
        foreach ($r as $row) {
            $dados[$row->req_status][] = (object)array('data' => $row);
        }

        //passa as variaveis para a view
        $_REQUEST['workflow']['requests_tabs'] = $statusLista;
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
        // Get viewl level id
        $reviewrs_group_id = $this->params->get('allow_review_request') == null ? $_POST["options"]["allow_review_request"] : $this->params->get('allow_review_request');
        $approve_for_own_records = $this->params->get('approve_for_own_records') == null ? $_POST["options"]["approve_for_own_records"] : $this->params->get('approve_for_own_records');
        $workflow_owner_element = $this->params->get('workflow_owner_element') == null ? $_POST["options"]["workflow_owner_element"] : $this->params->get('workflow_owner_element');

        // Get the groups from view level
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);
        $query->select($db->qn('rules'))
            ->from($db->qn('#__viewlevels'))
            ->where('id = ' . $reviewers_group_id);
        $db->setQuery($query);
        $db->execute();
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
            // If $owner_element_id has been setted
            if (isset($owner_element_id) && !empty($owner_element_id)) {
                $db = Factory::getContainer()->get('DatabaseDriver');
                $query = $db->getQuery(true);
                $query->select($db->qn('name'))
                    ->from($db->qn('#__fabrik_elements'))
                    ->where('id = ' . $owner_element_id);
                $db->setQuery($query);
                $db->execute();
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
        // Owner_id var
        $owner_id = null;

        //Configure models to use in easyadmin context
        if($this->easyadmin) {
            $listId = $formData['easyadmin_modal___listid'];
            $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
            $listModel->setId($listId);
            $formModel = $listModel->getFormModel();
            $this->params = $formModel->getParams();
            $this->fieldPrefix = 'req_';
            $this->listId = $listId;
        }

        if ($this->requestType == self::REQUEST_TYPE_ADD_RECORD) {
            // If the request type is add
            // Set the user element as the register owner

            // Get owner id element identifier
            $owner_element_id = $this->params->get('workflow_owner_element');
            // If $owner_element_id has been setted
            if (isset($owner_element_id) && !empty($owner_element_id)) {
                $db = Factory::getContainer()->get('DatabaseDriver');
                $query = $db->getQuery(true);
                $query->select($db->qn('name'))
                    ->from($db->qn('#__fabrik_elements'))
                    ->where('id = ' . $owner_element_id);
                $db->setQuery($query);
                $db->execute();
                $r = $db->loadObjectList();

                // Updates the owner_id var to the value
                $owner_element_name = $r[0]->name;
                $owner_id = $formData[$this->listName . '___' . $owner_element_name][0];
            }
        } else if ($this->requestType == self::REQUEST_TYPE_EDIT_RECORD) {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // Verify if has requests on log table 
            $req_record_id = $formData['rowid'];
            $query = $db->getQuery(true);
            // Get the last formData from #__fabrik_requests table
            $query
                ->select(array($db->qn('req_owner_id'), $db->qn("form_data")))
                ->from($db->qn("#__fabrik_requests"))
                ->where($db->qn("req_record_id") . ' = ' . "$req_record_id")
                ->where("(`req_status` = 'approved' or `req_status` = 'pre-approved')")
                ->order('req_id desc');
            $db->setQuery($query);
            $db->execute();

            $results = $db->loadObjectList();
            if (!$results[0]->req_owner_id > 0) {
                // Get owner id element identifier
                $owner_element_id = $this->params->get('workflow_owner_element');
                // If $owner_element_id has been setted
                if (isset($owner_element_id) && !empty($owner_element_id)) {
                    // Get oner id element name
                    $query = $db->getQuery(true);
                    $query->select($db->qn('name'))
                        ->from($db->qn('#__fabrik_elements'))
                        ->where('id = ' . $owner_element_id);
                    $db->setQuery($query);
                    $db->execute();
                    $r = $db->loadObjectList();
                    $owner_element_name = $r[0]->name;

                    $query = $db->getQuery(true);
                    $query->select(array($db->qn($owner_element_name) . ' as value',))
                        ->from($db->qn($this->listName))
                        ->where('id = ' . $formData['rowid']);
                    $db->setQuery($query);
                    $db->execute();
                    $r = $db->loadObjectList();

                    $owner_id = $r[0]->value;
                    $date_time = date('Y-m-d H:i:s');

                    $preApprovedLog = $this->createLogPreApproved($formData, $owner_id, $owner_id, $date_time);
                    $this->saveLog($preApprovedLog);
                }
            } else {
                $owner_id = $results[0]->req_owner_id;
            }
        } else if ($this->requestType == self::REQUEST_TYPE_DELETE_RECORD) {
            $owner_id = $formData["owner_id_raw"];
        } else if ($this->requestType == self::REQUEST_TYPE_ADD_FIELD) {
            $owner_id = $this->user->id;
        } else if($this->requestType == self::REQUEST_TYPE_EDIT_FIELD) {
            $element = $listModel->getElements('id', true, false)[$formData['easyadmin_modal___valIdEl']];
            $owner_id = $element->element->created_by;
        }

        if (isset($owner_id) && !empty($owner_id)) {
            $formData["owner_id"] = $owner_id;
        } else {
            die(Text::_("PLG_FORM_WORKFLOW_OWNER_NOT_SET"));
            return false;
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
            // Defines the return message
            if ($this->requestType == self::REQUEST_TYPE_DELETE_RECORD) {
                Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_DELETE_SUCESS_MESSAGE'), 'message');
            } else if ($this->requestType == self::REQUEST_TYPE_ADD_RECORD) {
                Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_CREATE_SUCESS_MESSAGE'), 'message');
            } else {
                Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_EDIT_SUCESS_MESSAGE'), 'message');
            }

            return false;
        }

        return true;
    }

    /**
     * This method creates a request in fabrik_workflow table, it is replacing this->processLog()
     * 
     * @param       Array           $formData           The formData array
     * @param       Boolean         $delete             The user wants delete record?
     * 
     * @return      Boolean
     */
    public function createRequest($formData, $delete=false)
    {
        // Verifies if it is in request_list
        if ($this->isRequestList()) {
            return true;
        }

        // Verifies the request type
        $this->setRequestType($formData, $delete);

        // Saves log to database
        $hasPermission = $this->hasPermission($formData);
        // @TODO - Analisar se if é mesmo necessário
        if ($this->requestType == self::REQUEST_TYPE_ADD_RECORD) {
            $owner_id = null;
            $owner_element_id = $this->params->get('workflow_owner_element');
            if (isset($owner_element_id) && !empty($owner_element_id)) {
                //get the element name to catch the owner_id from formdata
                $db = Factory::getContainer()->get('DatabaseDriver');
                $query = $db->getQuery(true);
                $query->select($db->qn('name'))
                    ->from($db->qn('#__fabrik_elements'))
                    ->where('id = ' . $owner_element_id);
                $db->setQuery($query);
                $db->execute();
                $r = $db->loadObjectList();
                $owner_element_name = $r[0]->name;
                $owner_id = $formData[$this->listName . '___' . $owner_element_name];
            }

            if (isset($owner_id) && !empty($owner_id)) {
                $formData["owner_id"] = $owner_id;
            } else {
                // sets owner_id
                $formData["owner_id"] = $this->user->id;
            }
        } else if ($this->requestType == self::REQUEST_TYPE_EDIT_RECORD)  {
           $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true);
            $query->select($db->qn('req_owner_id'))
                ->from($db->qn('#__fabrik_requests'))
                ->where('req_record_id = ' . $formData['rowid']);
            $db->setQuery($query);
            $db->execute();
            $r = $db->loadObjectList();

            $formData["owner_id"] = $r[0]->req_owner_id;
        }

        if (!$this->persistRequest($formData, $hasPermission)) {
            die(Text::_('PLG_FORM_WORKFLOW_PROCESS_LOG_FAIL'));
        }

        if (!$hasPermission) {
            //define a mensagem de retorno
            if ($this->requestType == self::REQUEST_TYPE_DELETE_RECORD) {
                Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_DELETE_SUCESS_MESSAGE'), 'message');
            } else if ($this->requestType == self::REQUEST_TYPE_ADD_RECORD) {
                Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_CREATE_SUCESS_MESSAGE'), 'message');
            } else {
                Factory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_EDIT_SUCESS_MESSAGE'), 'message');
            }

            return false;
        }

        return true;
    }

    /**
     * This method saves a request in fabrik_workflow table, it is replacing this->saveFormDataToLog()
     * 
     * @param       Array          $formData       The formData array
     * 
     * @return      Boolean
     */
    public function saveLog($formData)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);
        $mainListFormData = new StdClass;

        // Separates requestData and formData
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

    public function saveNotification($formData)
    {
        $reviewrs_id = $this->getReviewers();
        JLoader::register('FieldsHelper', JPATH_ADMINISTRATOR . '/components/com_fields/helpers/fields.php');

        foreach ($reviewrs_id as $id) {
            $field_id = 1;
            JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fields/models', 'FieldsModel');
            $fieldModel = JModelLegacy::getInstance('Field', 'FieldsModel', array('ignore_request' => true));
            $values = json_decode($fieldModel->getFieldValue($field_id, $id), true);
            $is_vote = $this->getParams()->get('workflow_approval_by_vote');
            if ($is_vote == 1){
                if ($formData['req_vote_approve'] != '' || $formData['req_vote_disapprove'] != ''){
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
                            // retirar apenas do usuario atual se for votacao
                            if (($formData['req_status'] == "verify") && ($is_vote == 0)) {
                                $values['requisicoes'][$k]['qtd']  = $v['qtd'] + 1;
                            } else if ($formData['req_status'] != "pre-approved"){
                                $values['requisicoes'][$k]['qtd'] = $v['qtd'] - 1;
                                if ($values['requisicoes'][$k]['qtd'] == 0) {
                                    unset($values['requisicoes'][$k]);
                                }
                            }

                            $value = json_encode($values);
                            JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fields/models', 'FieldsModel');
                            $fieldModel = JModelLegacy::getInstance('Field', 'FieldsModel', array('ignore_request' => true));
                            $fieldModel->setFieldValue($field_id, $id, $value);
                            break 1;
                        } 
                    }
                    if ($validador && ($formData['req_status'] == "verify")) {
                        $values['requisicoes'][$k + 1]['lista'] = intval($formData["req_list_id"]);
                        $values['requisicoes'][$k + 1]['qtd'] = 1;
                        $value = json_encode($values);
                        JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fields/models', 'FieldsModel');
                        $fieldModel = JModelLegacy::getInstance('Field', 'FieldsModel', array('ignore_request' => true));
                        $fieldModel->setFieldValue($field_id, $id, $value);
                    }
                }
        }
        return true;
    }

    public function newNotification($formData, $field_id, $user_id, $k)
    {
        $value = new StdClass;
        $value->requisicoes[$k]['lista'] = intval($formData["req_list_id"]);
        $value->requisicoes[$k]['qtd'] = 1;
        $value = json_encode($value);
        JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fields/models', 'FieldsModel');
        $fieldModel = JModelLegacy::getInstance('Field', 'FieldsModel', array('ignore_request' => true));
        $fieldModel->setFieldValue($field_id, $user_id, $value);
    }

    public function persistRequest($formData, $hasPermission)
    {
        $dados = $this->getFormDataToLog($formData);
        $dados[$this->fieldPrefix . "status"] = ($hasPermission ? 'pre-approved' : 'verify');

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $mainListFormData = new StdClass;

        // Separates requestData and formData
        $query->insert(self::REQUESTS_TABLE_NAME);
        foreach ($dados as $k => $v) {
            if (strpos($k, $this->fieldPrefix) !== false) {
                if (!empty($v)) {
                    $query->set("{$k} = " . $db->q($v));
                }
            } else {
                if (!empty($v)) {
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

        // Get request id
        $req_created_date = $dados['req_created_date'];
        $req_owner_id = $dados['req_owner_id'];
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);
        $query->select('req_id')
            ->from('#__fabrik_requests')
            ->where("req_created_date = '$req_created_date'");
        $db->setQuery($query);

        try {
            $db->execute();
        } catch (Exception $e) {
            var_dump($db->getQuery()->__toString());
            die(Text::_("PLG_FORM_WORKFLOW_ERROR_NOT_LOG_REGISTER"));
        }
        $request_id = $db->loadResult();

        $this->persistedRequestId = $request_id;
        $this->persistedRequestStatus = $dados[$this->fieldPrefix . "status"];

        // sends mail
        $sendMail = (bool) $this->params->get('workflow_send_mail');

        return true;
    }

    /**
     * This method count the requests
     * 
     * @return      Boolean
     */
    public function countRequests()
    {
        $approveOwn = (int)$this->params->get('approve_for_own_records');

        $status = 'verify';
        $whereUser = '';
        $whereList = "AND req_list_id = '{$this->listId}'";
        // @TODO Verificar se é para aprovar os proprios registros
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

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);
        $query->clear();

        try {
            $requestList = '#__fabrik_requests';
            $status = 'verify';

            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true);
            $query->clear();
            $sql = "SELECT (
                    -- requisicoes de novo registro
                    COALESCE((SELECT count(req_id) FROM {$requestList} WHERE req_status = '{$status}' 
                        AND (req_record_id IS NULL OR req_record_id = 0) {$whereUser} {$whereList}), 0) 
                    
                    -- requisicoes de alteracao/exclusao de registro
                    + COALESCE((SELECT count(req_id) FROM {$requestList} WHERE req_status = '{$status}' 
                        AND (req_record_id IS NOT NULL AND req_record_id <> 0) {$whereUser} {$whereList}), 0)
            ) as 'events'";

            $db->setQuery($sql);
            $r = $db->loadResult();
            $_REQUEST['workflow']['requests_count'] = $r;
            $_REQUEST['workflow']['label_request_aproval'] = Text::_('PLG_FORM_WORKFLOW_LABEL_REQUEST_APROVAL');
            $_REQUEST['workflow']['label_request_view'] = Text::_('PLG_FORM_WORKFLOW_LABEL_REQUEST_VIEW');

            //parametro para url de lista e form (depende se frontend ou backend)
            $opt = array('list' => '&view=list', 'form' => '&view=form', 'details' => '&view=details');
            if (stripos($_SERVER['REQUEST_URI'], 'administrator') !== false) {
                //backend
                $opt = array('list' => 'task=list.view', 'form' => 'task=list.form', 'details' => 'task=details.view');
            }
            $_REQUEST['workflow']['list_link'] = "index.php?option=com_fabrik&{$opt['list']}&listid={$this->listId}";
            $_REQUEST['workflow']['requests_link'] =  $_REQUEST['workflow']['listLinkUrl'] . "?wfl_action=list_requests#eventsContainer";
            $_REQUEST['workflow']['requests_form_link'] = "index.php?option=com_fabrik&{$opt['form']}&formid={$this->requestListFormId}&rowid=";
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
        $approveOwn = (int)$this->params->get('approve_for_own_records');

        $status = 'verify';
        $whereUser = '';
        // @TODO Verificar se é para aprovar os proprios registros
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

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);
        $query->clear();

        try {
            $requestList = '#__fabrik_requests';
            $status = 'verify';

            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true);
            $query->clear();
            $sql = "SELECT (
                    -- requisicoes de novo registro
                    COALESCE((SELECT count(req_id) FROM {$requestList} WHERE req_status = '{$status}' 
                        AND (req_record_id IS NULL OR req_record_id = 0) {$whereUser}), 0) 
                    
                    -- requisicoes de alteracao/exclusao de registro
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
     * This method updates the request to approved or not, and call the method to save the information to the target lists if is approved.
     * It is replacing this->processRequest()
     * 
     * @param       Array          $fullFormData           The full formData array
     * 
     * @return      Boolean
     */
    public function updateRequest($fullFormData)
    {
        $formData = $this->extractFormData($fullFormData);
        $rowid = $formData["rowid"];
        if ($formData['req_approval'] === '1' || $formData['req_approval'] === '0') {
            $status = $formData['req_approval'] === '1' ? 'approved' : 'not-approved';

            //atualiza o status do request
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true);
            $query->set("{$this->fieldPrefix}status = " . $db->q($status));
            $query->update('#__fabrik_requests')->where("{$this->fieldPrefix}id = " . $db->q($rowid));
            $db->setQuery($query);
            $db->execute();

            if ($status == 'approved') {
                $this->applyRequestChange($rowid);
            }

            //envia email com o resultado da operacao
            $this->enviarEmailRequestApproval($formData, $status);
        }

        return true;
    }

    /**
     * Add the heading information to the Fabrik list, so as to include a column for the add to cart link
     *
     * @return      Null
     */
    public function onGetPluginRowHeadings()
    {
        // Execute the method only once
        if (isset($_REQUEST['workflow']['init']) && $_REQUEST['workflow']['init'] == true) {
            return false;
        }

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

                        $whereClauses = array();

                        foreach ($ids as $id) {
                            $whereClauses[] = $db->qn($join_key_column) . ' = ' . $db->q($id);
                        }

                        $query
                            ->select(array($db->qn($join_val_column) . ' as value'))
                            ->from($db->qn($join_db_name))
                            ->where($whereClauses, 'OR');

                        $db->setQuery($query);
                        $db->execute();
                        $results = $db->loadObjectList();
                        $rawValues = array();
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

                        if (!in_array($this->user->id, $this->getReviewers())) {
                            // SE NAO FOR AUTORIZADO REGISTERED
                            if ($elementModel->getParams()->get('ajax_upload', '0') === '1') {
                                // SE FOR AJAX REGISTERED
                                $key       = 'fabrik.form.fileupload.files.' . $elementModel->getId();
                                $ajaxFiles = $this->session->get($key, []);
                                $ajaxFiles['path'] = $folder;
                                if ($ajaxFiles) {
                                    if (is_array($ajaxFiles['name'])) {
                                        $workflowElementUploadName = $this->listName . '_FILE_' . $completeElName;
                                        $formModel->formData[$workflowElementUploadName] = $ajaxFiles;
                                        $formData[$workflowElementUploadName] = $ajaxFiles;
                                    
                                        $_FILES[$element->name] = $ajaxFiles;
                                        $fileData      = $_FILES[$element->name]['name'];

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
                                    $file  = array(
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
                            }  else {
                                // SE NAO FOR AJAX
                                if (array_key_exists($completeElName, $_FILES) && $_FILES[$completeElName]['name'] != '') {
                                    // SE É A REQUISIÇÃO REGISTERED
                                    $file  = array(
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
                        } else {
                            // SE TEM AUTORIZACAO
                            if (empty($_FILES)) {
                                // SE TIVER APROVANDO REQUISIÇÃO
                                if (isset($formData[$this->listName . '_FILE_' . $completeElName])) {
                                    // SE JA TEM OS DADOS SALVOS DA REQUISIÇÃO DO REGISTERED
                                    if ($elementModel->getParams()->get('ajax_upload', '0') === '1') {
                                        //  É AJAX
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
                                } else {
                                    // SIGINIFA QUE ESTA VAZIO OU É AJAX
                                    if ($elementModel->getParams()->get('ajax_upload', '0') === '1') {
                                        // É AJAX
                                        $key       = 'fabrik.form.fileupload.files.' . $elementModel->getId();
                                        $ajaxFiles = $this->session->get($key, []);
                                        if ($ajaxFiles != null) {
                                            $file  = array(
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
                            } else {
                                // SE TEM AUTORIZAÇÃO E NAO ESTA VAZIO
                                if (array_key_exists($completeElName, $_FILES) && $_FILES[$completeElName]['name'] != '') {
                                // SE TEM AUTORIZAÇÃO E NÃO É AJAX
                                    $file  = array(
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

                    $formData[$completeElName]['cropdata'] = array(
                        $imageLink => 0
                    );
                    $formData[$completeElName]['crop'] = array(
                        $imageLink => 0
                    );
                    $formData[$completeElName . "_raw"]['cropdata'] = array(
                        $imageLink => 0
                    );
                    $formData[$completeElName . "_raw"]['crop'] = array(
                        $imageLink => 0
                    );
                }
            } else if ($value['plugin'] == 'tags') {
                // Catch tags values using ids
                $ids = $formData[$completeElName];
                if (!empty($ids) && $ids[0] != '') {
                    $tags_dbname = $value['tags_dbname'];

                    $query = $db->getQuery(true);

                    $whereClauses = array();

                    foreach ($ids as $id) {
                        $whereClauses[] = $db->qn('id') . ' = ' . $id;
                    }

                    $query
                        ->select(array($db->qn('title')))
                        ->from($db->qn($tags_dbname))
                        ->where($whereClauses, 'OR');

                    $db->setQuery($query);
                    $db->execute();
                    $results = $db->loadObjectList();
                    $rawValues = array();
                    foreach ($results as $v) {
                        $rawValues[] = $v->title;
                    }
                    $formData[$completeElName . "_value"] = $rawValues;
                }
            }
        }

        // Params to save request
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
        // Inits workflow
        $this->init();
        $formModel = $this->getModel();
        $fullFormData = $formModel->fullFormData;
        $processedFormData = $this->processFormDataToSave($fullFormData);
        $hasPermission = $this->hasPermission($processedFormData);
        $this->setRequestType($fullFormData, false);
        $isReview = false;

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
        $formModel = $this->getModel();

        // Update user own 
        $listModel = $formModel->getListModel();
        $owner_element_id = $this->params->get('workflow_owner_element');
        $owner_element = $listModel->getElements('id');
        $owner_element_name = $owner_element[$owner_element_id]->element->name;
        $table_name = $formModel->getTableName();

        $pk = $listModel->getPrimaryKeyAndExtra();
        $id_raw = $formModel->fullFormData[$table_name . '___' . $pk[0]['colname'] . '_raw'];
        $owner_id = $formModel->fullFormData[$table_name . '___' . $owner_element_name][0];

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);
        $query->set("{$owner_element_name} = " . $db->q($owner_id));
        $query->update($table_name)->where("{$pk[0]['colname']} = " . $db->q($id_raw));
        $db->setQuery($query);
        $db->execute();

        if (isset($this->isReview) && $this->isReview) {
            $row_id = $formModel->fullFormData['rowid'];
            $req_id = $formModel->fullFormData['req_id'];

            // Update record id
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true);
            $query->clear();
            $query->set("req_record_id = " . $db->q($row_id))->set("req_reviewer_id = " . $db->q($this->user->id));
            $query->update('#__fabrik_requests')->where("req_id = " . $db->q($req_id));
            $db->setQuery($query);
            $db->execute();
        } else {
            // Get the form data
            $fullFormData = $formModel->fullFormData;
            // Process form data to save later
            $processedFormData = $this->processFormDataToSave($fullFormData);
            $hasPermission = $this->hasPermission($processedFormData);

            return $this->createLog($processedFormData, $hasPermission);
        }
    }

    /**
     * Get the table name to insert/updates
     * 
     * @return      String
     */
    protected function getTableName()
    {
        $params = $this->getParams();
        $listId = $params->get('table');
        $listModel = JModelLegacy::getInstance('list', 'FabrikFEModel');
        $listModel->setId($listId);

        return $listModel->getTable()->db_table_name;
    }

    /**
	 * Init function
	 *
	 * @return  	Null
	 */
    private function init()
    {
        $this->loadTranslationsOnJS();
        //set list name
        try {
            $formModel = $this->getModel();
            $form = $formModel->getForm();
            $listId = $formModel->getListModel()->getId();
            $db = Factory::getContainer()->get('DatabaseDriver');
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
            //get request list and form id
            try {
                $formModel = $this->getModel();
                $form = $formModel->getForm();
                $listId = $formModel->getListModel()->getId();
                $db = Factory::getContainer()->get('DatabaseDriver');
                $db->setQuery("SELECT " . $db->qn('id') . ", " . $db->qn('form_id') . " FROM " . $db->qn('#__fabrik_lists') . " WHERE " . $db->qn('db_table_name') . " = '{$this->requestListName}'");
                $r = $db->loadObjectList();
                if (count($r) > 0) {
                    $this->requestListId = $r[0]->id;
                    $this->requestListFormId = $r[0]->form_id;
                }
            } catch (Exception $e) {
            }
        }

        //set field prefix
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
     * Extracts only the main data from the form without the auxiliary Joomla fields
     * 
     * @param       Array       $fullFormData       The full formData array
     * 
     * @return      Array
     */
    protected function extractFormData($fullFormData)
    {
        $listName = $this->listName;
        $rowid = $fullFormData["rowid"];
        $campos = array('rowid' => $rowid);

        foreach ($fullFormData as $k => $v) {
            if (stripos($k, "{$listName}__") !== false) {
                $field = str_replace(array("{$listName}___", "_raw"), '', $k);
                if (stripos($k, '_raw') !== false) {
                    continue;
                }
                $new_k = str_replace("{$listName}___", '', $k);
                // If is an array and has only one element:
                if (!($v['is_files'] == 'true')) {
                    if (is_array($v) && count($v) == 1) {
                        // Gets firs element
                        $v = $v[0];
                    } else if (is_array($v) && count($v) > 1) {
                        // If is an array with more than one element
                        // Transform to JSON
                        $v = json_encode($v);
                    }
                }

                $campos[$new_k] = $v;
            }
        }

        return $campos;
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
        $listModel = $this->getModel()->getListModel();
        $groups = Factory::getApplication()->getIdentity()->getAuthorisedViewLevels();
        
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
        $groups = Factory::getApplication()->getIdentity()->getAuthorisedViewLevels();
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
        $groups = Factory::getApplication()->getIdentity()->getAuthorisedViewLevels();
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
        $_REQUEST['workflow']['addRequestLink'] = $listModel->getAddRecordLink() . '?wfl_action=request';
        $_REQUEST['workflow']['listLinkUrl'] = explode('?', $listModel->getTableAction())[0];
        $_REQUEST['workflow']['requestLabel'] = Text::_('PLG_FORM_WORKFLOW_BUTTON_NEW_REQUEST');
        $_REQUEST['workflow']['eventsButton'] = Text::_('PLG_FORM_WORKFLOW_BUTTON_EVENTS');
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
     * Saves data to log/request table
     * 
     * @param       Array           $formData               The full formData array
     * @param       Boolean         $hasPermission          User has permission or not?
     * 
     * @return      Boolean
     */
    protected function saveFormDataToLog($formData, $hasPermission)
    {
        $dados = $this->getFormDataToLog($formData);
        $dados[$this->fieldPrefix . "status"] = ($hasPermission ? 'pre-approved' : 'verify');

        $this->createOrUpdateLogTable();
        $requestListName = $this->requestListName;

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);
        $query->insert($requestListName);
        foreach ($dados as $k => $v) {
            $query->set("{$k} = " . $db->q($v));
        }
        $db->setQuery($query);
        $db->execute();

        return true;
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
     * @param       Int             $userId             xxxxxxxxxxxxxxxxxxxxxxx
     * @param       Int             $ownerId            xxxxxxxxxxxxxxxxxxxxxxx
     * @param       String          $date_time          xxxxxxxxxxxxxxxxxxxxxxx
     * 
     * @return      Array
     */
    protected function createLogPreApproved($formData, $userId, $ownerId, $date_time)
    {
        $newFormData = array();
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
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            //default log columns
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

            //get list columns
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

            //check if request table exists
            $db->setQuery("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $this->requestListName . "'");
            $request_table_exists = (bool) $db->loadResult();

            if (!$request_table_exists) {
                //create table
                $clabels_createdb = implode(", ", $clabelsCreateDb);
                $create_custom_table = "CREATE TABLE IF NOT EXISTS " . $db->qn($this->requestListName) . " ($clabels_createdb);";
                $db->setQuery($create_custom_table);
                $db->execute();
            } else {
                //check if default list and request list fields are different
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
        $requestTableColumns = array();
        $clabelsCreateDb = array();

        foreach ($columns as $column) {
            if (stripos($column->Field, $this->fieldPrefix) !== false) {
                continue;
            }
            $requestTableColumns[$column->Field] = $column->Field;

            //verifica se ha coluna que nao esta presente na tabela principal
            if (!key_exists($column->Field, $defaultTableColumns)) {
                $clabelsCreateDb[] = "DROP COLUMN " . $db->qn($column->Field);
            }
        }

        foreach ($defaultTableColumns as $column => $desc) {
            //verifica se ha coluna ainda nao presente na tabela de request
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
        $request_type =  $this->requestTypes[$request['req_request_type_id']];

        $request_user = $this->user;
        $owner_user = !empty($request['owner_id']) ? Factory::getUser($request['owner_id']) : null;

        $emailTo = array($request_user->email);
        //email para o dono do registro
        if ($owner_user && $owner_user->id != $request_user->id) {
            $emailTo[] = $owner_user->email;
        }

        // Catch all reviewers data
        jimport('joomla.access.access');
        $reviewers_id = $this->getReviewers();

        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
        $listModel->setId($request['listid']);

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

        $owner_user_email = !empty($request["req_user_email"]) ? $request["req_user_email"] : null;
        //email para o solicitante da mudanca
        $emailTo = Array();

        //email para o dono do registro
        if ($owner_user_email) {
            $emailTo[] = $owner_user_email;
        }

        $list_id = $request["req_list_id"];
        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
        $listModel->setId($list_id);

        $list_label = $listModel->getLabel();
        $user_name = $request["req_user_name"];
        $request_type = $request["req_request_type_id"];
        $request_status = $request['req_approval'] === '1' ? 'Aprovada' : 'Não Aprovada';

        $menu = $app->getMenu();
        $menuLinked = $menu->getItems('link', "index.php?option=com_fabrik&view=list&listid=$list_id", true);
        $alias = $menuLinked->alias;
        $link = URI::root() . $alias;

        switch ($request_type) {
            case '1':
                $request_type = Text::_("PLG_FORM_WORKFLOW_ADD_RECORD_EMAIL");
                break;
            case '2':
                $request_type = Text::_("PLG_FORM_WORKFLOW_EDIT_FIELD_RECORD_EMAIL");
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
        $query->select("*")->from($this->listName)
            ->where("{$this->fieldPrefix}id = " . $myDb->q($rowId));
        $myDb->setQuery($query);
        $row = $myDb->loadAssoc();

        return $row;
    }

    /**
     * This method applies the request changes to the main table record
     * 
     * @param       Int         $rowid      The row id to change 
     * 
     * @return      Boolean
     */
    public function applyRequestChange($rowid)
    {
        try {
            $dbTable = str_replace($this->dbtable_request_sufixo, '', $this->listName);
            $pkey = 'id';
            if (!($rowData = $this->getRowData($rowid))) {
                throw new Exception('Request not found!');
            }
            $requestType = $rowData['req_request_type_id'];
            $record_id = $rowData["{$this->fieldPrefix}record_id"];

            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true);

            if ($requestType == self::REQUEST_TYPE_DELETE_RECORD) {
                $query->delete($db->qn($dbTable))
                    ->where(array($db->qn($pkey) . " = '{$record_id}'"));
            } else {
                $columns = $values = $strValues = array();
                foreach ($rowData as $k => $v) {
                    if (stripos($k, $this->fieldPrefix) !== false) {
                        continue;
                    }
                    if ($k == $pkey) {
                        continue;
                    }
                    $columns[] = $k;
                    $values[] = $db->q($v);
                    $strValues[] = "{$db->qn($k)} = {$db->q($v)}";
                    echo ("k: $k - v: $v <br>");
                }
                if ($requestType == self::REQUEST_TYPE_ADD_RECORD) {
                    $query->insert($db->qn($dbTable))
                        ->columns($db->qn($columns))
                        ->values(implode(',', $values));
                } else {
                    $query->update($db->qn($dbTable))
                        ->set($strValues)
                        ->where(array($db->qn($pkey) . " = {$record_id}"));
                }
            }
            $db->setQuery($query);
            $db->execute();
            return true;
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }
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
     * 
     * 
     * @return      Null
     */
    public function onReportAbuse()
    {
        $app = Factory::getApplication();
        $listRowIds = $_REQUEST['listRowIds'];
        $optionsJs = $_REQUEST['options'];
        $listId = explode(":", $listRowIds)[0];
        $rowId = explode(":", $listRowIds)[1];
        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
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

        $date = Factory::getDate();
        $usuario = &Factory::getApplication()->getIdentity();
        $data['req_id'] = '';
        $data['req_request_type_id'] = '3';
        $data['req_user_id'] = $usuario->get('id');;
        $data['req_field_id'] = '';
        $data['req_created_date'] = $date->toSQL();;
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
     * Install the plugin db tables
     *
     * @return      Null
     */
    public function install()
    {
        $db = FabrikWorker::getDbo();

        /* Create the tables */
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
			`id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY ,
			`name` VARCHAR(20) DEFAULT NULL
        )";

        $db->setQuery($sqlType)->execute();

        $select = "SELECT * FROM `workflow_request_type`";
        $db->setQuery($select)->execute();
        $result = $db->loadObjectList();

        if (empty($result)) {
            /* Update existing tables */
            $sqls = [
                "INSERT INTO `workflow_request_type` VALUES (1,'add_record'), (2,'edit_field_value'), (3,'delete_record'), (4,'add_field'), (5,'edit_field');",
            ];

            foreach ($sqls as $sql) {
                $db->setQuery($sql)->execute();
            }
        }
    }
}