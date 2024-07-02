<?php

/**
 * Workflow
 * 
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.workflow
 * @copyright   Copyright (C) 2018-2024  Marcel Ferrante - All rights reserved.
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

    protected $requestTypes = [1 => "add_record", 2 => "edit_record", 3 => "delete_record"];

    const REQUESTS_TABLE_NAME = '#__fabrik_requests';


    // Plugin update in October - 2019

    // Ajax functions

    function onGetSessionToken()
    {
        echo JFactory::getSession()->getFormToken();
    }

    /**
     * This function gets the request list from
     * the server.
     * @param approve_for_own_records
     * @param wfl_action
     * @param list_id
     * @param user_id
     * @return req_status
     * @ajax this function is called by AJAX
     */
    function onGetRequestList()
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
        $db = JFactory::getDbo();
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
            ->select('workflow_request_type.name as req_request_type_name')
            ->select('form_data')
            ->from($db->quoteName('#__fabrik_requests'))
            ->join('INNER', "workflow_request_type on (req_request_type_id = workflow_request_type.id)")
            ->join('INNER', $db->quoteName('#__users', 'u_req') . ' ON (' . $db->quoteName('req_user_id') . ' = ' . $db->quoteName('u_req.id') . ')')
            ->join('LEFT', $db->quoteName('#__users', 'u_owner') . ' ON (' . $db->quoteName('req_owner_id') . ' = ' . $db->quoteName('u_owner.id') . ')')
            ->join('LEFT', $db->quoteName('#__users', 'u_rev') . ' ON (' . $db->quoteName('req_reviewer_id') . ' = ' . $db->quoteName('u_rev.id') . ')')
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

    // Get the last formData from #__fabrik_requests table
    // to compare on approve a edit request
    function onGetLastRecordFormData()
    {
        $req_record_id = $_REQUEST['req_record_id'];
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);

        // Get the last formData from #__fabrik_requests table
        $query
            ->select($db->quoteName("form_data"))
            ->from($db->quoteName("#__fabrik_requests"))
            ->where($db->quoteName("req_record_id") . ' = ' . "$req_record_id")
            ->where("(`req_status` = 'approved' or `req_status` = 'pre-approved')")
            ->order('req_id desc');
        $db->setQuery($query);
        $db->execute();

        $formData = $db->loadObjectList();
        echo $formData[0]->form_data;
    }

    /**
     * This function gets the files uploaded
     * @param parent_table_name
     * @param element_name
     * @param parent_id
     * @ajax this function is called by AJAX
     */
    function onGetFileUpload()
    {
        // Filter the request
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');

        // Get the params from requests
        $parent_table_name = $request['parent_table_name'];
        $element_name = $request['element_name'];
        $parent_id = $request['parent_id'];

        // Get DB and query object
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);

        // Get all images where parent_id is equal to $parent_id
        $query
            ->select($db->quoteName($element_name) . ' as value')
            ->from($db->quoteName($parent_table_name . "_repeat_" . $element_name))
            ->where($db->quoteName("parent_id") . ' = ' . "$parent_id");
        $db->setQuery($query);
        $db->execute();

        $ids = $db->loadObjectList();

        // Encode and return images paths
        echo json_encode($ids);
    }
    // Apagar?
    function onCanApproveRequest()
    {
        require_once JPATH_COMPONENT . '/controller.php';
        $app = JFactory::getApplication();
        $app->set('jquery', true);
        $input = $app->input;
        $controllerName = $input->getCmd('view');
        //        FabrikControllerList
        $package = $input->get('package', 'fabrik');
        $controller = new FabrikControllerList;
        $app->setUserState('com_fabrik.package', $package);
        $controller->execute('process');
        echo "<html>";
        echo "<pre>";
        var_dump($controller);
        echo "</pre>";
        echo "</html>";
    }

    function onGetElementsPlugin($list_id = null)
    {
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');

        $req_list_id = $list_id ? $list_id : $request['req_list_id'];

        // Recebe o obj para acessar o DB
        $db = JFactory::getDBO();
        $subQuery = $db->getQuery(true);
        // Sub Query to catch the group_id using a list_id
        $subQuery
            ->select($db->quoteName('b.group_id'))
            ->from($db->quoteName('#__fabrik_lists', 'a'))
            ->join('INNER', $db->quoteName('#__fabrik_formgroup', 'b') .
                ' ON ' . $db->quoteName('a.form_id') . ' = ' . $db->quoteName('b.form_id'))
            ->where($db->quoteName('a.id') . ' = ' . $req_list_id);

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
                ->select(array($db->quoteName('name'), $db->quoteName('plugin'), $db->quoteName('params')))
                ->from($db->quoteName('#__fabrik_elements'))
                ->where($db->quoteName('group_id') . ' = ' . "$group_id");

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

    function onGetDatabaseJoinMultipleData()
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
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);

        // Pega os IDs originais
        $query
            ->select($db->quoteName($element_name) . ' as value')
            ->from($db->quoteName($parent_table_name . "_repeat_" . $element_name))
            ->where($db->quoteName("parent_id") . ' = ' . "$parent_id");

        $db->setQuery($query);
        $db->execute();

        $ids = $db->loadObjectList();

        if (!empty($ids)) {
            // Pega o raw dos ids originais
            $query = $db->getQuery(true);

            $whereClauses = array();

            foreach ($ids as $id) {
                $whereClauses[] = $db->quoteName($join_key_column) . ' = ' . $db->q($id->value);
            }

            $query
                ->select(array($db->quoteName($join_val_column) . ' as value', 'id'))
                ->from($db->quoteName($join_db_name))
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
            $whereClauses[] = $db->quoteName($join_key_column) . ' = ' . $db->q($id);
        }
        $query
            ->select(array($db->quoteName($join_val_column) . ' as value', 'id'))
            ->from($db->quoteName($join_db_name))
            ->where($whereClauses, 'OR');

        $db->setQuery($query);
        $db->execute();
        $results = $db->loadObjectList();

        $response->request = $results;
        echo json_encode($response);
    }

    // Delete was removed from workflow 01/04/20
    // function onDeleteRecord() {
    //     $filter = JFilterInput::getInstance();
    //     $request = $filter->clean($_REQUEST, 'array');

    //     $list_name = $request['list_name'];
    //     $record_id = $request['record_id'];
    //     $db = JFactory::getDbo();

    //     $query = $db->getQuery(true);

    //     $conditions = array(
    //         $db->quoteName('id') . ' = ' . $record_id,
    //     );

    //     $query->delete($db->quoteName($list_name));
    //     $query->where($conditions);

    //     $db->setQuery($query);

    //     $result = $db->execute();

    //     echo $result;
    // }

    function onGetDatabaseJoinSingleData()
    {
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');

        $join_val_column = $request['join_val_column'];
        $join_key_column = $request['join_key_column'];
        $join_db_name = $request['join_db_name'];
        $element_id = $request['element_id'];
        $original_element_id = $request['original_element_id'];



        // Recebe o obj para acessar o DB
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);

        $query
            ->select($db->quoteName($join_val_column) . ' as value')
            ->from($db->quoteName($join_db_name))
            ->where($db->quoteName($join_key_column) . ' = ' . "$element_id");

        $db->setQuery($query);
        $db->execute();

        $results = $db->loadObjectList();

        $return = new stdClass;
        $return->new = $results;


        if (!empty($original_element_id)) {
            $query = $db->getQuery(true);

            $query
                ->select($db->quoteName($join_val_column) . ' as value')
                ->from($db->quoteName($join_db_name))
                ->where($db->quoteName($join_key_column) . ' = ' . "$original_element_id");

            $db->setQuery($query);
            $db->execute();
            $results = $db->loadObjectList();
            $return->original = $results;
        }

        echo json_encode($return);
    }

    function onUploadFileToRequest()
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




        //        $req_list_id = $request['req_list_id'];

    }

    public function onGetRequest()
    {
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');
        $req_id = $request['req_id'];
        // Recebe o obj para acessar o DB
        $db = JFactory::getDBO();

        // Cria novo obj query
        $query = $db->getQuery(true);

        // // Seleciona identificador e valor
        $query->select(array('a.*', 'b.name as req_user_name', 'c.name as req_owner_name', 'd.name as req_reviewer_id_raw'));

        // Da tabela $join_name
        $query->from($db->quoteName('#__fabrik_requests', 'a'));

        // If has req_id, set where
        if (isset($_GET['req_id'])) {
            $req_id = $_GET['req_id'];
            $query->join('LEFT', $db->quoteName('#__users', 'b') . ' ON (' . $db->quoteName('a.req_user_id') . ' = ' . $db->quoteName('b.id') . ')');
            $query->join('LEFT', $db->quoteName('#__users', 'c') . ' ON (' . $db->quoteName('a.req_owner_id') . ' = ' . $db->quoteName('c.id') . ')');
            $query->join('LEFT', $db->quoteName('#__users', 'd') . ' ON (' . $db->quoteName('a.req_reviewer_id') . ' = ' . $db->quoteName('d.id') . ')');
            $query->where($db->quoteName('req_id') . ' = ' . $db->quote($req_id));
        } else {
            die('Error getting requests, no req_id was passed.');
        }

        // Aplica a query no obj DB
        $db->setQuery($query);

        // Salva resultado da query em results
        $results = $db->loadObjectList();

        // Codifica $results para JSON
        echo json_encode($results);
    }

    function onGetUserValue()
    {
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');
        $userId = $request['user_id'];

        $user = JFactory::getUser($userId);

        echo $user->name;
    }

    function onGetUserValueBeforeAfter()
    {
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');
        $lastUserId = $request['last_user_id'];
        $newUserId = $request['new_user_id'];

        $last = JFactory::getUser($lastUserId);
        $new = JFactory::getUser($newUserId);

        $r = new StdClass;
        $r->last = $last->name;
        $r->new = $new->name;

        echo json_encode($r);
    }

    /*
     * Called by AJAX, this method updates the request record
     * on the #__fabrik_requests table
     */
    function onProcessRequest()
    {
        $fieldsToUpdate = array(
            "req_id",
            "req_approval",
            "req_comment",
            "req_file",
            "req_reviewer_id"
        );

        $return = new StdClass;
        $filter = JFilterInput::getInstance();
        $request = $filter->clean($_REQUEST, 'array');
        $sendMail = $request['sendMail'];

        // Catch ajax params
        $requestData = $request['formData'][0];
        $data = $requestData;

        foreach ($requestData as $key => $value) {
            if (!in_array($key, $fieldsToUpdate)) {
                unset($requestData[$key]);
            }
        }

        // Get Joomla DB obj
        $db = JFactory::getDBO();

        $requestData['req_revision_date'] = date("Y-m-d H:i:s");
        $requestData['req_status'] = $requestData['req_approval'] === '1' ? 'approved' : 'not-approved';

        $usuario = &JFactory::getUser();
        $requestData['req_reviewer_id'] = $requestData['req_reviewer_id'] === '' ? "{$usuario->id}" : $requestData['req_reviewer_id'];

        $obj = (object) $requestData;
        $results = $db->updateObject('#__fabrik_requests', $obj, 'req_id', false);
        $return->response = true;

        // @TODO - SEND MAIL
        if($sendMail == true) {
            $this->enviarEmailRequestApproval($data, $data['req_record_id']);
        }

        echo json_encode($return);
    }

    // Ajax functions end

    function loadTranslationsOnJS()
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

        Text::script('PLG_FORM_WORKFLOW_LOG');
    }

    /*
     * Updates the record on the main list
     */
    function saveToMainList($formData, $requestData, $sendMail)
    {
        try {
            $filesElements = array();
            // Recebe o obj para acessar o DB
            $db = JFactory::getDBO();
            $query = $db->getQuery(true);
            $subQuery = $db->getQuery(true);
            // Sub Query to catch the group_id using a list_id
            $subQuery
                ->select($db->quoteName('b.group_id'))
                ->from($db->quoteName('#__fabrik_lists', 'a'))
                ->join('INNER', $db->quoteName('#__fabrik_formgroup', 'b') .
                    ' ON ' . $db->quoteName('a.form_id') . ' = ' . $db->quoteName('b.form_id'))
                ->where($db->quoteName('a.id') . ' = ' . $requestData['req_list_id']);
            // Query to catch plugins type from all elements from a group_id
            $query
                ->select(array($db->quoteName('name'), $db->quoteName('plugin')))
                ->from($db->quoteName('#__fabrik_elements'))
                ->where($db->quoteName('group_id') . ' = ' . "($subQuery)");
            // All the query have this pattern:
            // SELECT `plugin` FROM h4rjm_fabrik_elements WHERE group_id = (SELECT h4rjm_fabrik_formgroup.group_id FROM h4rjm_fabrik_lists inner join h4rjm_fabrik_formgroup on h4rjm_fabrik_lists.form_id = h4rjm_fabrik_formgroup.form_id where h4rjm_fabrik_lists.id = 23)
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
                $query->delete($db->quoteName($listName))
                    ->where(array($db->quoteName('id') . " = '{$record_id}'"));
            } else {
                $columns = $values = $strValues = array();
                foreach ($formData as $k => $v) {
                    // if (stripos($k, 'req_') !== false) {
                    //     continue;
                    // }
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
                                    $query->delete($db->quoteName($table_name))
                                        ->where(array($db->quoteName('parent_id') . " = '{$record_id}'"));
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
                                $values[] = $db->quote($v);
                                $strValues[] = "{$db->quoteName($k)} = {$db->quote($v)}";
                                break;
                        }
                    } else {
                        $columns[] = $k;
                        $values[] = $db->quote($v);
                        $strValues[] = "{$db->quoteName($k)} = {$db->quote($v)}";
                    }
                }
                $query = $db->getQuery(true);
                if ($requestData['req_request_type_id'] == "add_record") {
                    $query->insert($db->quoteName($listName))
                        ->columns($db->quoteName($columns))
                        ->values(implode(',', $values));
                } else  if ($requestData['req_request_type_id'] == "edif_field_value") {
                    $query->update($db->quoteName($listName))
                        ->set($strValues)
                        ->where(array($db->quoteName('id') . " = {$record_id}"));
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
                    ->insert($db->quoteName($object->table_name))
                    ->columns($db->quoteName($columns))
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
                        $values = array($db->quote($record_id), $db->quote($link));

                        $query
                            ->insert($db->quoteName($saveToTableName))
                            ->columns($db->quoteName($columns))
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

            JFactory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }
    }



    function getListName($listId)
    {
        // Recebe o obj para acessar o DB
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $query->select($db->quoteName('db_table_name'));
        $query->from($db->quoteName('#__fabrik_lists'));
        $query->where($db->quoteName('id') . ' = ' . $listId);
        // Reset the query using our newly populated query object.
        $db->setQuery($query);
        // Load the results as a list of stdClass objects (see later for more options on retrieving data).
        $results = $db->loadObjectList();
        return $results[0];
    }


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
        // @TODO remove approve_for_own_records option
        $options->user->approve_for_own_records = $this->params->get('approve_for_own_records');
        $options->wfl_action = $wfl_action;
        $options->user->canApproveRequests = $this->canApproveRequests();
        $options->allow_review_request = $this->getParams()->get('allow_review_request');
        $options->workflow_owner_element = $this->params->get('workflow_owner_element');
        $options->root_url = JURI::root();
        $options->sendMail = $sendMail;
        $options = json_encode($options);
        $jsFiles = array();
        $jsFiles['Fabrik'] = 'media/com_fabrik/js/fabrik.js';
        $jsFiles['FabrikWorkflow'] = 'plugins/fabrik_form/workflow/workflow.js';
        $script = "var workflow = new FabrikWorkflow($options);";
        FabrikHelperHTML::script($jsFiles, $script);
    }

    /**
     * This method pass the list of requests to
     * the view, it is replacing this->processLog()
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
        $db = JFactory::getDbo();
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
            ->select('workflow_request_type.name as req_request_type_name')
            ->from(self::REQUESTS_TABLE_NAME)
            ->join('INNER', "workflow_request_type on (req_request_type_id = workflow_request_type.id)")
            ->join('INNER', $db->quoteName('#__users', 'u_req') . ' ON (' . $db->quoteName('req_user_id') . ' = ' . $db->quoteName('u_req.id') . ')')
            ->join('LEFT', $db->quoteName('#__users', 'u_owner') . ' ON (' . $db->quoteName('req_owner_id') . ' = ' . $db->quoteName('u_owner.id') . ')')
            ->join('LEFT', $db->quoteName('#__users', 'u_rev') . ' ON (' . $db->quoteName('req_reviewer_id') . ' = ' . $db->quoteName('u_rev.id') . ')')
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
        //$_REQUEST['workflow']['requests_isGrouped'] = true;
        //$_REQUEST['workflow']['requests_grouptemplates'] = $statusLista;
        ////$_REQUEST['workflow']['requests_group_by_show_count'] = false;
    }

    /*
     * This function get all reviewers of the workflow plugin for this form
     * */

    function getReviewers($row = null)
    {
        jimport('joomla.access.access');
        // Get viewl level id
        $reviewrs_group_id = $this->params->get('allow_review_request');

        // Get the groups from view level
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select($db->quoteName('rules'))
            ->from($db->quoteName('#__viewlevels'))
            ->where('id = ' . $reviewrs_group_id);
        $db->setQuery($query);
        $db->execute();
        $r = $db->loadObjectList();

        $groups = json_decode($r[0]->rules);
        $allUsers = array();

        foreach ($groups as $group) {
            $users = JAccess::getUsersByGroup($group);
            foreach ($users as $user) {
                $allUsers[] = $user;
            }
        }

        if ($this->params->get('approve_for_own_records') == 1){
            $owner_element_id = $this->params->get('workflow_owner_element');
            // If $owner_element_id has been setted
            if (isset($owner_element_id) && !empty($owner_element_id)) {
                // Get the DB object
                $db = JFactory::getDbo();
                // Get a new query object
                $query = $db->getQuery(true);
                // Get the owner id element name from fabrik's elements table
                $query->select($db->quoteName('name'))
                    ->from($db->quoteName('#__fabrik_elements'))
                    ->where('id = ' . $owner_element_id);
                $db->setQuery($query);
                $db->execute();
                // Get the query results
                $r = $db->loadObjectList();
                $owner_element_name = $r[0]->name;
                // Updates the owner_id var to the value
                if ($row){
                    $owner_id = $row->{$this->model->form->db_table_name.'___'.$owner_element_name.'_raw'};
                } else {
                    $owner_id = $_REQUEST[$this->listName . '___' . $owner_element_name][0];
                }
                if ($owner_id == $this->user->id){
                    $allUsers[] = $owner_id;
                }
            }
        }
        return $allUsers;
    }


    // Create the log object
    public function creatLog($formData, $hasPermission)
    {
        // Set the request type var
        // $this->setRequestType($formData, false);

        // Owner_id var
        $owner_id = null;

        if ($this->requestType == self::REQUEST_TYPE_ADD_RECORD) {
            // If the request type is add
            // Set the user element as the register owner

            // Get owner id element identifier
            $owner_element_id = $this->params->get('workflow_owner_element');
            // If $owner_element_id has been setted
            if (isset($owner_element_id) && !empty($owner_element_id)) {
                // Get the DB object
                $db = JFactory::getDbo();
                // Get a new query object
                $query = $db->getQuery(true);
                // Get the owner id element name from fabrik's elements table
                $query->select($db->quoteName('name'))
                    ->from($db->quoteName('#__fabrik_elements'))
                    ->where('id = ' . $owner_element_id);
                $db->setQuery($query);
                $db->execute();
                // Get the query results
                $r = $db->loadObjectList();
                $owner_element_name = $r[0]->name;
                // Updates the owner_id var to the value
                $owner_id = $formData[$this->listName . '___' . $owner_element_name][0];
            }
        } else if ($this->requestType == self::REQUEST_TYPE_EDIT_RECORD) {
            // Get the DB object
            $db = JFactory::getDbo();

            // Verify if has requests on log table 
            $req_record_id = $formData['rowid'];
            $query = $db->getQuery(true);
            // Get the last formData from #__fabrik_requests table
            $query
                ->select(array($db->quoteName('req_owner_id'), $db->quoteName("form_data")))
                ->from($db->quoteName("#__fabrik_requests"))
                ->where($db->quoteName("req_record_id") . ' = ' . "$req_record_id")
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
                    // Get a new query object
                    $query = $db->getQuery(true);
                    // Get the owner id element name from fabrik's elements table
                    $query->select($db->quoteName('name'))
                        ->from($db->quoteName('#__fabrik_elements'))
                        ->where('id = ' . $owner_element_id);
                    $db->setQuery($query);
                    $db->execute();
                    // Get the query results
                    $r = $db->loadObjectList();
                    $owner_element_name = $r[0]->name;

                    // Get the owner_id
                    // Get a new query object
                    $query = $db->getQuery(true);
                    $query->select(array($db->quoteName($owner_element_name) . ' as value', $db->quoteName("date_time"),))
                        ->from($db->quoteName($this->listName))
                        ->where('id = ' . $formData['rowid']);
                    $db->setQuery($query);
                    $db->execute();
                    //  // Get the query results
                    $r = $db->loadObjectList();
                    $owner_id = $r[0]->value;
                    $date_time = $r[0]->date_time;

                    $preApprovedLog = $this->createLogPreApproved($formData, $owner_id, $owner_id, $date_time);
                    $this->saveLog($preApprovedLog);
                }
            } else {
                $owner_id = $results[0]->req_owner_id;
            }



            // // If the request type is edit
            // // Preserve the original owner_id
            // // Get a new query object
            // $query = $db->getQuery(true);
            // $query->select($db->quoteName('req_owner_id'))
            //     ->from($db->quoteName('#__fabrik_requests'))
            //     ->where('req_record_id = '. $formData['rowid']);

            // $db->setQuery($query);

            // $db->execute();
            // //  // Get the query results
            // $r = $db->loadObjectList();
            // var_dump($db->getQuery()->__toString());
            // $owner_id = $r[0]->req_owner_id;
        } else if ($this->requestType == self::REQUEST_TYPE_DELETE_RECORD) {
            $owner_id = $formData["owner_id_raw"];
        }

        if (isset($owner_id) && !empty($owner_id)) {
            $formData["owner_id"] = $owner_id;
        } else {
            echo "Erro ao pegar owner_id";
            die();
        }



        $logData = $this->getFormDataToLog($formData, $hasPermission);

        if (!$this->saveLog($logData)) {
            die(Text::_('PLG_FORM_WORKFLOW_PROCESS_LOG_FAIL'));
        }

        if ($this->params->get('workflow_send_mail') == '1'){
            $this->enviarEmailRequest($logData);
        }

        if (!$hasPermission) {
            //define a mensagem de retorno
            if ($this->requestType == self::REQUEST_TYPE_DELETE_RECORD) {
                JFactory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_DELETE_SUCESS_MESSAGE'), 'message');
            } else if ($this->requestType == self::REQUEST_TYPE_ADD_RECORD) {
                JFactory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_CREATE_SUCESS_MESSAGE'), 'message');
            } else {
                JFactory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_EDIT_SUCESS_MESSAGE'), 'message');
            }

            return false;
        }
        return true;
    }
    /**
     * This method creates a request in 
     * fabrik_workflow table, it is replacing
     * this->processLog()
     */
    public function createRequest($formData, $delete = false)
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
                $db = JFactory::getDbo();
                $query = $db->getQuery(true);
                $query->select($db->quoteName('name'))
                    ->from($db->quoteName('#__fabrik_elements'))
                    ->where('id = ' . $owner_element_id);
                $db->setQuery($query);
                $db->execute();
                $r = $db->loadObjectList();
                $owner_element_name = $r[0]->name;
                $owner_id = $formData[$this->listName . '___' . $owner_element_name];
            }

            // echo "<pre>";
            // var_dump($owner_id);
            // echo "</pre>";
            // die();

            if (isset($owner_id) && !empty($owner_id)) {
                $formData["owner_id"] = $owner_id;
            } else {
                // sets owner_id
                $formData["owner_id"] = $this->user->id;
            }
        } else if ($this->requestType == self::REQUEST_TYPE_EDIT_RECORD) {
            $db = JFactory::getDbo();
            $query = $db->getQuery(true);
            $query->select($db->quoteName('req_owner_id'))
                ->from($db->quoteName('#__fabrik_requests'))
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
                JFactory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_DELETE_SUCESS_MESSAGE'), 'message');
            } else if ($this->requestType == self::REQUEST_TYPE_ADD_RECORD) {
                JFactory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_CREATE_SUCESS_MESSAGE'), 'message');
            } else {
                JFactory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_EDIT_SUCESS_MESSAGE'), 'message');
            }

            return false;
        }
        return true;
    }




    /**
     * This method saves a request in 
     * fabrik_workflow table, it is replacing
     * this->saveFormDataToLog()
     */

    public function saveLog($formData)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $mainListFormData = new StdClass;

        // Separates requestData and formData
        $query->insert(self::REQUESTS_TABLE_NAME);
        foreach ($formData as $k => $v) {
            if (strpos($k, $this->fieldPrefix) !== false) {
                if (!empty($v)) {
                    $query->set("{$k} = " . $db->quote($v));
                }
            } else {
                if (!empty($v)) {
                    if (end(explode('_', $k)) == 'orig') {
                        $k = array_shift(explode('_orig', $k));
                    }
                    $mainListFormData->$k = $v;
                }
            }
        }

        $mainListFormDataJson = json_encode($mainListFormData);

        $query->set("form_data = " . $db->quote($mainListFormDataJson));
        $db->setQuery($query);

        try {
            $db->execute();
        } catch (Exception $e) {
            return false;
        }

        // @TODO - CATCH ID TO SEND MAIL

        return true;
    }

    public function persistRequest($formData, $hasPermission)
    {

        $dados = $this->getFormDataToLog($formData);
        $dados[$this->fieldPrefix . "status"] = ($hasPermission ? 'pre-approved' : 'verify');

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);

        $mainListFormData = new StdClass;

        // Separates requestData and formData
        $query->insert(self::REQUESTS_TABLE_NAME);
        foreach ($dados as $k => $v) {
            if (strpos($k, $this->fieldPrefix) !== false) {
                if (!empty($v)) {
                    $query->set("{$k} = " . $db->quote($v));
                }
            } else {
                if (!empty($v)) {
                    $mainListFormData->$k = $v;
                }
                // $query->set("{$k} = " . $db->quote($v));
            }
        }

        $mainListFormDataJson = json_encode($mainListFormData);

        $query->set("form_data = " . $db->quote($mainListFormDataJson));
        $db->setQuery($query);
        try {
            $db->execute();
        } catch (Exception $e) {
            return false;
        }

        // Get request id
        $req_created_date = $dados['req_created_date'];
        $req_owner_id = $dados['req_owner_id'];
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $query->select('req_id')
            ->from('#__fabrik_requests')
            ->where("req_created_date = '$req_created_date'");
        // ->where("req_created_date = '$req_created_date' and req_owner_id = $req_owner_id");
        $db->setQuery($query);

        try {
            $db->execute();
        } catch (Exception $e) {
            var_dump($db->getQuery()->__toString());

            echo "Não há log desse registro no banco de dados, o que impossibilita completar esta tarefa!";
            die('ERRO');
            return false;
        }
        $request_id = $db->loadResult();

        $this->persistedRequestId = $request_id;
        $this->persistedRequestStatus = $dados[$this->fieldPrefix . "status"];

        // sends mail
        $sendMail = (bool) $this->params->get('workflow_send_mail');
        // @TODO - $this->enviarEmailRequestApproval($requestData, $record_id) verify
        //        if($sendMail) {
        //            $this->enviarEmailRequestApproval($dados, $request_id);
        //        }
        return true;
    }

    /**
     * This method count the 
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


        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->clear();


        try {
            $requestList = '#__fabrik_requests';
            $status = 'verify';

            $db = JFactory::getDbo();
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
            $_REQUEST['workflow']['requests_link'] =  $_REQUEST['workflow']['listLinkUrl']."?wfl_action=list_requests#eventsContainer";
            // $_REQUEST['workflow']['requests_link'] = "index.php?option=com_fabrik&{$opt['list']}&listid={$this->listId}&wfl_action=list_requests#eventsContainer";
            // $_REQUEST['workflow']['requests_link'] = "index.php/" . $this->listName . "?wfl_action=list_requests&layout=bootstrap#eventsContainer";
            $_REQUEST['workflow']['requests_form_link'] = "index.php?option=com_fabrik&{$opt['form']}&formid={$this->requestListFormId}&rowid=";
            $_REQUEST['workflow']['requests_details_link'] = "index.php?option=com_fabrik&{$opt['details']}&formid={$this->requestListFormId}&rowid=";
            return true;
        } catch (Exception $e) {
            //die($e->getTraceAsString());
            return false;
        }
    }

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

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->clear();


        try {
            $requestList = '#__fabrik_requests';
            $status = 'verify';

            $db = JFactory::getDbo();
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
            //die($e->getTraceAsString());
            return false;
        }
    }

    /**
     * This method updates the request to approved or not, and call the method to save
     * the information to the target lists if is approved it is replacing
     * this->processRequest()
     * $formModel->fullFormData
     */
    public function updateRequest($fullFormData)
    {
        $formData = $this->extractFormData($fullFormData);
        $rowid = $formData["rowid"];
        if ($formData['req_approval'] === '1' || $formData['req_approval'] === '0') {
            $status = $formData['req_approval'] === '1' ? 'approved' : 'not-approved';

            //atualiza o status do request
            $db = JFactory::getDbo();
            $query = $db->getQuery(true);
            $query->set("{$this->fieldPrefix}status = " . $db->quote($status));
            $query->update('#__fabrik_requests')->where("{$this->fieldPrefix}id = " . $db->quote($rowid));
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
    // End
    /**
     * Add the heading information to the Fabrik list, so as to include a column for the add to cart link
     *
     * @param array $args
     *
     * @return void
     */
    public function onGetPluginRowHeadings()
    {
        if (isset($_REQUEST['workflow']['init']) && $_REQUEST['workflow']['init'] == true) {
            //executar o metodo apenas uma vez
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

    function processFormDataToSave($formData)
    {
        // Catch db obj
        $db = JFactory::getDBO();
        // catch databasejoin joins
        $elementsPlugins = $this->onGetElementsPlugin($formData['listid']);
        $elementModels = $this->getListElementModels();
        $listModel = $this->getModel()->getListModel();

        $formModel = $this->getModel();

        foreach ($elementsPlugins as $key => $value) {
            $completeElName = $this->listName . "___" . $key;
            // if plugin is databasejoin
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
                            $whereClauses[] = $db->quoteName($join_key_column) . ' = ' . $db->q($id);
                        }

                        $query
                            ->select(array($db->quoteName($join_val_column) . ' as value'))
                            ->from($db->quoteName($join_db_name))
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
                            if ($elementModel->getParams()->get('ajax_upload', '0') === '1'){
                                // SE FOR AJAX REGISTERED
                                $key       = 'fabrik.form.fileupload.files.' . $elementModel->getId();
                                $ajaxFiles = $this->session->get($key, []);
                                $ajaxFiles['path'] = $folder;
                                if ($ajaxFiles){
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
                                            array_push($completeElNameData, JPath::clean($folder.$file['name']));
            
                                            $tmpFile  = $file['tmp_name'];
                                            // if(strlen($file['name']) > 0){
                                            //     $formData[$completeElName] = $folder . $file['name'];
                                            // }                            
                                            if ($storage->appendServerPath()) {
                                                $folderPath = JPATH_SITE . '/' . $folder. '/' . $file['name'];
                                            }
                                            $filePath = JPath::clean($folderPath);
                                            copy($tmpFile, $filePath);      
                                        }
                                        $workflowElementUploadName = $this->listName . '_FILE_' . $completeElName;
                                        $formModel->updateFormData($completeElName, $completeElNameData);
                                        $formModel->updateFormData($completeElName.'_raw', $completeElNameData);
                                        $formData[$completeElName] = $completeElNameData;
                                        $formData[$completeElName.'_raw'] = $completeElNameData;
                                        $formModel->updateFormData($workflowElementUploadName, $file);
                                        $formModel->formData[$workflowElementUploadName] = $file;
                                        //$formData[$workflowElementUploadName] = $file;
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
                                    $completeElNameData = JPath::clean($folder.$file['name']);
                                    $formModel->updateFormData($completeElName, $completeElNameData);
                                    $formModel->updateFormData($completeElName.'_raw', $completeElNameData);
                                    $formModel->updateFormData($workflowElementUploadName, $file);
    
                                    $tmpFile  = $file['tmp_name'];
                                    if(strlen($file['name']) > 0){
                                        $formData[$completeElName] = $folder . $file['name'];
                                    }                            
                                    if ($storage->appendServerPath()) {
                                        $folder = JPATH_SITE . '/' . $folder;
                                    }
                                    $folder = $folder . '/' . $file['name'];
                                    $filePath = JPath::clean($folder);
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
                                    $completeElNameData = JPath::clean($folder.$file['name']);
                                    $formData[$workflowElementUploadName] = $file;
                                    $tmpFile  = $file['tmp_name'];
                                                     
                                    if ($storage->appendServerPath()) {
                                        $folder = JPATH_SITE . '/' . $folder;
                                    }
                                    $folder = $folder . '/' . $file['name'];
                                    $filePath = JPath::clean($folder);
                                    
                                    copy($tmpFile, $filePath);
                                    $formModel->updateFormData($completeElName, $completeElNameData);
                                    $formModel->updateFormData($completeElName.'_raw', $completeElNameData);
                                    //$storage->upload($tmpFile, $filePath);
                                }  
                            }                     
                        } else {
                            // SE TEM AUTORIZACAO
                             if (empty($_FILES)) {
                                    // SE TIVER APROVANDO REQUISIÇÃO
                                    if (isset($formData[$this->listName . '_FILE_' . $completeElName])) {
                                        // SE JA TEM OS DADOS SALVOS DA REQUISIÇÃO DO REGISTERED
                                        if ($elementModel->getParams()->get('ajax_upload', '0') === '1'){
                                        //  É AJAX
                                        $db_table_name = $this->listName.'_repeat_'.$element->name;
                                        foreach ($formData[$completeElName] as $i => $f) {
                                            $oRecord = (object) [
                                                'parent_id'   => $formData['rowid'],
                                                $element->name  => $formData[$completeElName][$i]
                                            ];
                                            $listModel->insertObject($db_table_name, $oRecord, false);
                                        }
                                        //$formData[$completeElName] = '';
                                        } else { 
                                        $_FILES[$completeElName]['name'] = $formData[$this->listName . '_FILE_' . $completeElName]['name'];
                                        $_FILES[$completeElName]['type'] = $formData[$this->listName . '_FILE_' . $completeElName]['type'];
                                        $_FILES[$completeElName]['tmp_name'] = $formData[$this->listName . '_FILE_' . $completeElName]['tmp_name'];
                                        $_FILES[$completeElName]['error'] = $formData[$this->listName . '_FILE_' . $completeElName]['error'];
                                        $_FILES[$completeElName]['size'] = $formData[$this->listName . '_FILE_' . $completeElName]['size'];
                                        $_FILES[$completeElName]['workflow'] = '1';
    
                                        $imagePath = JPATH_SITE . '/' . $folder . '/' . $_FILES[$completeElName]['name'];
                                        $imagePath = JPath::clean($imagePath);
                                        $newPath =  $_FILES[$completeElName]['tmp_name'];
                                        copy($newPath, $imagePath);
                                        unset($formData[$this->listName . '_FILE_' . $completeElName]);
                                        $completeElNameData = JPath::clean($folder.$_FILES[$completeElName]['name']);
                                        $formData[$completeElName] = $completeElNameData;
                                        $formModel->updateFormData($completeElName, $completeElNameData);
                                        }                                        
                                    } else {
                                        // SIGINIFA QUE ESTA VAZIO OU É AJAX
                                        if ($elementModel->getParams()->get('ajax_upload', '0') === '1'){
                                            // É AJAX
                                            $key       = 'fabrik.form.fileupload.files.' . $elementModel->getId();
                                            $ajaxFiles = $this->session->get($key, []);
                                            if ($ajaxFiles != null){
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
                                                $completeElNameData = JPath::clean($folder.$file['name']);
                                                $formData[$completeElName] = $completeElNameData;
                                                $formData[$completeElName.'_raw'] = $completeElNameData;
                                                $formModel->updateFormData($completeElName, $completeElNameData);
                                                $formModel->updateFormData($completeElName.'_raw', $completeElNameData);
                                                $formModel->updateFormData($workflowElementUploadName, $file);
                                                    
                                                $imagePath = JPATH_SITE . '/' . $completeElNameData;
                                                $imagePath = JPath::clean($imagePath);
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
    
                                            $completeElNameData = JPath::clean($folder.$file['name']);
    
                                            if(strlen($file['name']) > 0){
                                                $formModel->formData[$completeElName] = $folder . $file['name'];
                                            }                            
                                            if ($storage->appendServerPath()) {
                                                $folder = JPATH_SITE . '/' . $folder;
                                            }
    
                                            $workflowElementUploadName = $this->listName . '_FILE_' . $completeElName;
                                            $formModel->formData[$workflowElementUploadName] = $file;
                                            $folder = $folder . '/' . $file['name'];
                                            $filePath = JPath::clean($folder);
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
                        $whereClauses[] = $db->quoteName('id') . ' = ' . $id;
                    }

                    $query
                        ->select(array($db->quoteName('title')))
                        ->from($db->quoteName($tags_dbname))
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
        // New obj to save the params and elements
        $newFormData = array();
        // Foreach that catchs only elements of the list if is no raw
        foreach ($formData as $key => $value) {
            if ((strpos($key, $this->listName) !== false ||
                strpos($key, 'repeat_group') !== false ||
                strpos($key, 'hiddenElements') !== false)) {
                //if is raw
                //                if(strpos($key, '_raw') !== false) {
                //                    $idsKey = str_replace("_raw", "", $key);
                //                    // if raw value is not equal to id values
                //                    if($formData[$key] != $formData[$idsKey]) {
                //                        $newFormData[$key] = $value;
                //                    }
                //                } else {
                $newFormData[$key] = $value;
                //                }

            }
        }
        // Foreach that catchs params to make the request to save the register later
        foreach ($ajaxParams as $value) {
            $newFormData[$value] = $formData[$value];
        }
        return $newFormData;
    }
    public function uploadFiles(){

        // $tmpFile  = $file['tmp_name'];
        // $completeElNameData = JPath::clean($folder.$file['name']);
        // if(strlen($file['name']) > 0){
        //     $formModel->formData[$completeElName] = $folder . $file['name'];
        // }     

        // if ($storage->appendServerPath()) {
        //     $folder = JPATH_SITE . '/' . $folder;
        // }

        // $workflowElementUploadName = $this->listName . '_FILE_' . $completeElName;
        // $formModel->formData[$workflowElementUploadName] = $file;
        // $folder = $folder . '/' . $file['name'];
        // $filePath = JPath::clean($folder);
        // copy($tmpFile, $filePath);
    }

    /**
     * Run right at the beginning of the form processing
     *
     * @return bool
     */
    public function onBeforeProcess()
    {
        // Inits workflow
        $this->init();
        // Get the form model
        $formModel = $this->getModel();
        // Get the form data
        $fullFormData = $formModel->fullFormData;
        // Process form data to save later
        $processedFormData = $this->processFormDataToSave($fullFormData);
        // Get user permission
        $hasPermission = $this->hasPermission($processedFormData);
        // Set request type
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
            return $this->creatLog($processedFormData, $hasPermission);
        }
    }

    // public function onBeforeProcess() {
    //     $this->init();
    //     $formModel = $this->getModel();
    //     $fullFormData = $formModel->fullFormData;
    //     $isReview = false;
    //     if(isset($fullFormData['review']) && $fullFormData['review']) {
    //         $isReview = true;
    //     }

    //     $processedFormData = $this->processFormDataToSave($fullFormData);       

    //     if(!$isReview) {
    //         if($this->hasPermission($processedFormData)) {
    //             $this->createRequest($processedFormData);
    //         } else {
    //             return $this->createRequest($processedFormData);
    //         }
    //     } else {
    //         $this->isReview = true;
    //         $this->req_id = $fullFormData['req_id'];
    //     }
    // }

    // Returns if can bypass workflow
    function canAdd()
    {
        $listModel = $this->getModel()->getListModel();
        $params = $listModel->getParams();
        $groups = $this->user->getAuthorisedViewLevels();
        $canAdd = in_array($params->get('allow_add'), $groups);
        return $canAdd;
    }

    // Delete was removed from workflow 01/04/20
    // public function onDeleteRowsForm(&$groups) {
    //     //die('onDeleteRowForm');
    //     $this->init();
    //     $rows = array();
    //     // $formModel = $this->getModel();
    //     // $fullFormData = $formModel->fullFormData;
    //     // if(isset($fullFormData['review']) && $fullFormData['review']) {
    //     //     $isReview = true;
    //     // }
    //     // if(!$isReview) {

    //         foreach ($groups[0][0] as $row) {
    //             $rows[] = array("rowid" => $row->__pk_val);
    //         }

    //         $rowid = $rows[0]["rowid"];

    //         $db = JFactory::getDBO();
    //         $query = $db->getQuery(true);

    //         // Get the last formData from #__fabrik_requests table
    //         $query
    //             ->select($db->quoteName("req_owner_id"))
    //             ->from($db->quoteName("#__fabrik_requests"))
    //             ->where($db->quoteName("req_record_id") . ' = ' . "$rowid" )
    //             ->order('req_created_date asc');
    //         $db->setQuery($query);
    //         $db->execute();
    //         $results = $db->loadResult();

    //         // Update owner_id
    //         $rows["owner_id"] = $results;

    //         return $this->createRequest($rows, true);

    //         // if($this->hasPermission($processedFormData)) {
    //         //     $this->createRequest($rows, true);
    //         // } else {
    //         //     return $this->createRequest($rows, true);
    //         // }
    //     // } else {
    //     //     $this->isReview = true;
    //     //     $this->req_id = $fullFormData['req_id'];
    //     // }
    // }

    /**
     * Run right at the end of the form processing
     * form needs to be set to record in database for this to hook to be called
     *
     * @return    bool
     */
    public function onAfterProcess()
    {
        // Get the form and list model
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

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->set("{$owner_element_name} = " . $db->quote($owner_id));
        $query->update($table_name)->where("{$pk[0]['colname']} = " . $db->quote($id_raw));
        $db->setQuery($query);
        $db->execute();

        if (isset($this->isReview) && $this->isReview) {
            $row_id = $formModel->fullFormData['rowid'];
            $req_id = $formModel->fullFormData['req_id'];

            // Update record id
            $db = JFactory::getDbo();
            $query = $db->getQuery(true);
            $query->clear();
            $query->set("req_record_id = " . $db->quote($row_id))->set("req_reviewer_id = " . $db->quote($this->user->id));
            $query->update('#__fabrik_requests')->where("req_id = " . $db->quote($req_id));
            $db->setQuery($query);
            $db->execute();

            // It's a review 
            // echo "ARE ON AFTER PROCESS";
            // echo "<pre>";
            //     var_dump("row_id", $row_id);
            //     var_dump("req_id", $req_id);
            //     var_dump("isReview", $this->isReview, "req_id", $this->req_id);
            // echo "</pre>";
            // die("IS REVIEW");
        } else {
            // Get the form data
            $fullFormData = $formModel->fullFormData;
            // Process form data to save later
            $processedFormData = $this->processFormDataToSave($fullFormData);
            $hasPermission = $this->hasPermission($processedFormData);
            return $this->creatLog($processedFormData, $hasPermission);
        }

        // if(isset($this->isReview) && $this->isReview) {
        // $formModel = $this->getModel();
        // $row_id = $formModel->fullFormData['rowid'];
        // $req_id = $formModel->fullFormData['req_id'];
        //     // Updates the record_id on pre-approved records
        //     // and on reviewing requests
        // $db = JFactory::getDbo();
        // $query = $db->getQuery(true);
        // $query->set("req_record_id = " . $db->quote($row_id));
        // $query->update('#__fabrik_requests')->where("req_id = " . $db->quote($req_id));
        // $db->setQuery($query);
        // $db->execute();
        // // Update if is fileupload
        // $formData = $formModel->fullFormData;
        // $tableName = $this->listName . "_repeat_" . $elementName;
        // $elementName = 
        // foreach($formData as $elementKey => $element) {
        //     if(
        //         strpos($elementKey, $this->listName."___") !== false && 
        //         isset($element->crop)
        //     ) {

        //     }
        // }

        // } else {
        //     if($this->persistedRequestStatus === "pre-approved") {
        //         $formModel = $this->getModel();
        //         $row_id = $formModel->fullFormData['rowid'];
        //         $db = JFactory::getDbo();
        //         $query = $db->getQuery(true);
        //         $query->set("req_record_id = " . $db->quote($row_id));
        //         $query->update('#__fabrik_requests')->where("req_id = " . $db->quote($this->persistedRequestId));
        //         $db->setQuery($query);
        //         $db->execute();
        //     }
        // }
    }

    /**
     * Get the table name to insert / update to
     * @return  string
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
     * Inicializa o plugin
     */
    private function init()
    {
        $this->loadTranslationsOnJS();
        //set list name
        try {
            $formModel = $this->getModel();
            $form = $formModel->getForm();
            $listId = $formModel->getListModel()->getId();
            $db = JFactory::getDBO();
            $db->setQuery("SELECT " . $db->qn('db_table_name') . " FROM " . $db->qn('#__fabrik_lists') . " WHERE " . $db->qn('form_id') . " = " . (int) $form->id);
            $listName = $db->loadResult();
        } catch (Exception $e) {
            $listId = null;
            $listName = '';
        }
        $this->listId = $listId;
        $this->listName = $listName;

        $this->dbtable_request_sufixo = '_request'; //$params->get('record_in');
        $this->checkIsRequestList();

        if (!$this->isRequestList()) {
            $this->requestListName = $this->listName . $this->dbtable_request_sufixo;
            //get request list and form id
            try {
                $formModel = $this->getModel();
                $form = $formModel->getForm();
                $listId = $formModel->getListModel()->getId();
                $db = JFactory::getDBO();
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
        $fieldPrefix = 'req_'; //$params->get('field_prefix');
        $this->fieldPrefix = $fieldPrefix;
    }

    /**
     * Executa os processos de log da lista principal
     * @param type $forms
     * @param type $delete
     * @return boolean
     * @deprecated replaced by $this->createRequest
     */
    private function processLog($forms, $delete = false)
    {
        if ($this->isRequestList()) {
            return true;
        }

        //verifica o tipo de request
        $this->setRequestType($forms[0], $delete);

        //salva o log
        $hasPermission = false;
        foreach ($forms as $form) {
            $formData = $this->extractFormData($form);
            $hasPermission = $this->hasPermission($formData);

            if (!$this->saveFormDataToLog($formData, $hasPermission)) {
                die(Text::_('PLG_FORM_WORKFLOW_PROCESS_LOG_FAIL'));
            }
            if (!$hasPermission) {
                if ($this->requestType == self::REQUEST_TYPE_ADD_RECORD && empty($formData["owner_id"])) {
                    //define o usuario dono do registro caso nao tenha
                    $formData["owner_id"] = $this->user->id;
                }
                //envia email
                $this->enviarEmailRequest($formData);
            }
        }

        if (!$hasPermission) {
            //define a mensagem de retorno
            if ($this->requestType == self::REQUEST_TYPE_DELETE_RECORD) {
                JFactory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_DELETE_SUCESS_MESSAGE'), 'message');
            } else if ($this->requestType == self::REQUEST_TYPE_ADD_RECORD) {
                JFactory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_CREATE_SUCESS_MESSAGE'), 'message');
            } else {
                JFactory::getApplication()->enqueueMessage(Text::_('PLG_FORM_WORKFLOW_RECORD_EDIT_SUCESS_MESSAGE'), 'message');
            }

            return false;
        }
        return true;
    }

    /**
     * Executa os processos da lista de request
     * @return boolean
     */
    private function processRequest($fullFormData)
    {
        //         die('Saved Request');
        if (!$this->isRequestList()) {
            return true;
        }
        $formData = $this->extractFormData($fullFormData);
        $rowid = $formData["rowid"];
        if ($formData['req_approval'] === '1' || $formData['req_approval'] === '0') {
            $status = $formData['req_approval'] === '1' ? 'approved' : 'not-approved';

            //atualiza o status do request
            $db = JFactory::getDbo();
            $query = $db->getQuery(true);
            $query->set("{$this->fieldPrefix}status = " . $db->quote($status));
            $query->update($this->listName)->where("{$this->fieldPrefix}id = " . $db->quote($rowid));
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
     * Define o valor do atributo requestType
     * @param type $form
     * @param type $delete
     */
    private function setRequestType($form, $delete)
    {
        if ($delete === true) {
            $this->requestType = self::REQUEST_TYPE_DELETE_RECORD;
        } else if (empty($form['rowid'])) {
            $this->requestType = self::REQUEST_TYPE_ADD_RECORD;
        } else {
            $this->requestType = self::REQUEST_TYPE_EDIT_RECORD;
        }
    }

    /**
     * Extrai somente os dados principais do formulario (sem os campos auxiliares do Joomla)
     * @param type $fullFormData
     * @return type
     */
    protected function extractFormData($fullFormData)
    {
        $listName = $this->listName;
        $rowid = $fullFormData["rowid"];
        $campos = array('rowid' => $rowid);
        foreach ($fullFormData as $k => $v) {
            if (stripos($k, "{$listName}__") !== false) {
                $field = str_replace(array("{$listName}___", "_raw"), '', $k);
                //if (stripos($k, '_raw') !== false || in_array($field, array('id', 'date_time', 'modified_by'))) {
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

                // $v = is_array($v) ? $v[0] : $v; //<- Erro
                $campos[$new_k] = $v;
            }
        }
        return $campos;
    }

    /**
     * Verifica se o usuario tem permissao para executar a ação e permite ou nao, 
     * persistir os dados no banco de dados
     * Atualizado em Março de 2020
     * @return boolean
     */
    protected function hasPermission($formData, $delete = false, $listModel = false, $optionsJs = false)
    {
        if (!isset($this->requestType)) {
            $this->setRequestType($formData, $delete);
        }

        if ($this->requestType == self::REQUEST_TYPE_DELETE_RECORD) {
            $listModel = $listModel;
        } else {
            $listModel = $this->getModel()->getListModel();
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
                if (!$canAdd) {
                    return false;
                }
                break;
            case self::REQUEST_TYPE_EDIT_RECORD:
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

    protected function canRequest()
    {
        $listModel = $this->getModel()->getListModel();
        $groups = JFactory::getUser()->getAuthorisedViewLevels();
        return in_array($listModel->getParams()->get('allow_request_record'), $groups);
    }

    /**
     * Verifica quais requisições o usuário pode ver.
     * @return string (all || only_own)
     */
    protected function canViewRequests($allow_review_request = '')
    {
        $groups = JFactory::getUser()->getAuthorisedViewLevels();
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
     * Verifica se o usuario pode aprovar requisições
     * @return boolean
     */
    protected function canApproveRequests()
    {
        $groups = JFactory::getUser()->getAuthorisedViewLevels();
        if ($this->user->authorise('core.admin')) {
            return true;
        } else if (in_array($this->getParams()->get('allow_review_request'), $groups)) {
            return true;
        }
        return false;
    }

    protected function checkAddRequestButton()
    {
        $listModel = $this->getModel()->getListModel();
        $_REQUEST['workflow']['showAddRequest'] = !$listModel->canAdd() && $this->canRequest();
        $_REQUEST['workflow']['addRequestLink'] = $listModel->getAddRecordLink() . '?wfl_action=request';
        $_REQUEST['workflow']['listLinkUrl'] = explode('?',$listModel->getTableAction())[0];
        $_REQUEST['workflow']['requestLabel'] = Text::_('PLG_FORM_WORKFLOW_BUTTON_NEW_REQUEST');
        $_REQUEST['workflow']['eventsButton'] = Text::_('PLG_FORM_WORKFLOW_BUTTON_EVENTS');
    }

    protected function checkEventsButton()
    {
        //$showEventsButton = $this->canViewRequests();
        $showEventsButton = true;
        if (!$this->countRequests()) {
            $showEventsButton = false;
        }
        $_REQUEST['workflow']['showEventsButton'] = $showEventsButton;
    }

    /**
     * Salva os dados na tabela de log/request
     * @param type $formData
     * @param type $hasPermission
     * @return boolean
     */
    protected function saveFormDataToLog($formData, $hasPermission)
    {
        $dados = $this->getFormDataToLog($formData);
        $dados[$this->fieldPrefix . "status"] = ($hasPermission ? 'pre-approved' : 'verify');

        $this->createOrUpdateLogTable();
        $requestListName = $this->requestListName;

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->insert($requestListName);
        foreach ($dados as $k => $v) {
            $query->set("{$k} = " . $db->quote($v));
        }
        $db->setQuery($query);
        $db->execute();
        //$id = $db->insertid();
        return true;
    }

    /**
     * Adiciona os demais campos de log junto aos dados do formulario
     * @param type $formData
     * @return array
     */
    protected function getFormDataToLog($formData, $hasPermission = false)
    {
        $fieldPrefix = $this->fieldPrefix;

        if ($this->requestType == self::REQUEST_TYPE_EDIT_RECORD || ($hasPermission && !$this->isReview)) {
            $rowid = $formData["rowid"] ? $formData["rowid"] : $formData[0]["rowid"];
            $formData[$fieldPrefix . "record_id"] = $rowid;
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
        $newFormData[$fieldPrefix . "description"] = "created by workflow";
        $newFormData[$fieldPrefix . "comment"] = "created by workflow";
        $rowid = $formData["rowid"] ? $formData["rowid"] : $formData[0]["rowid"];
        $newFormData[$fieldPrefix . "record_id"] = $rowid;
        return $newFormData;
    }

    protected function getListElementModels()
    {
        $elements = array();
        $formModel = $this->getModel();
        $groups = $formModel->getGroupsHiarachy();
        foreach ($groups as $groupModel) {
            //$group = $groupModel->getGroup();
            //$elementModels = $groupModel->getPublishedElements();
            $elementModels = $groupModel->elements;
            foreach ($elementModels as $elementModel) {
                $elements[] = $elementModel;
            }
        }
        return $elements;
    }

    /**
     * Create or update log table
     * @return boolean
     */
    protected function createOrUpdateLogTable()
    {
        try {
            $db = JFactory::getDBO();

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
     * Update log table
     * @param type $defaultTableColumns
     */
    private function updateLogTable($defaultTableColumns)
    {
        $db = JFactory::getDBO();
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
     * @deprecated replaced by countRequests()
     */
    protected function countEvents()
    {
        try {
            $requestList = $this->requestListName;
            $status = 'verify';
            $whereUser = '';
            if ($this->canViewRequests() == 'only_own') {
                $whereUser = "AND req_user_id = '{$this->user->id}'";
            }

            $db = JFactory::getDbo();
            $query = $db->getQuery(true);
            $query->clear();
            $sql = "SELECT (
                    -- requisicoes de novo registro
                    COALESCE((SELECT count(id) FROM {$requestList} WHERE req_status = '{$status}' 
                        AND (req_record_id IS NULL OR req_record_id = 0) {$whereUser}), 0) 
                    
                    -- requisicoes de alteracao/exclusao de registro
                    + COALESCE((SELECT count(req_id) FROM {$requestList} WHERE req_status = '{$status}' 
                        AND (req_record_id IS NOT NULL AND req_record_id <> 0) {$whereUser}), 0)
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
            $_REQUEST['workflow']['list_link'] = "index.php/" . $this->listName;
            $_REQUEST['workflow']['requests_link'] = "index.php/" . $this->listName . "?layout=bootstrap" . "&wfl_action=list_requests";
            // $_REQUEST['workflow']['list_link'] = "index.php?option=com_fabrik&{$opt['list']}&listid={$this->listId}";
            // $_REQUEST['workflow']['requests_link'] = "index.php?option=com_fabrik&{$opt['list']}&listid={$this->listId}&wfl_action=list_requests";
            $_REQUEST['workflow']['requests_form_link'] = "index.php?option=com_fabrik&{$opt['form']}&formid={$this->requestListFormId}&rowid=";
            $_REQUEST['workflow']['requests_details_link'] = "index.php?option=com_fabrik&{$opt['details']}&formid={$this->requestListFormId}&rowid=";
            return true;
        } catch (Exception $e) {
            //die($e->getTraceAsString());
            return false;
        }
    }

    private function enviarEmailRequest($request = null, $req_id = null)
    {
        $request_type =  $this->requestTypes[$request['req_request_type_id']];

        $request_user = $this->user;
        $owner_user = !empty($request['owner_id']) ? JFactory::getUser($request['owner_id']) : null;

        //email para o solicitante da mudanca
        $emailTo = array($request_user->email);
        //email para o dono do registro
        if ($owner_user && $owner_user->id != $request_user->id) {
            $emailTo[] = $owner_user->email;
        }

        // Catch all reviewrs data
        jimport('joomla.access.access');
        $reviewrs_id = $this->getReviewers();
        $reviewrs_data = array();

        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
        $listModel->setId($request['listid']);

        foreach ($reviewrs_id as $id) {
            $reviewrs_data[] = JFactory::getUser($id);
        }

        foreach ($reviewrs_data as $reviewr) {
            $emailTo[] = $reviewr->email;
        }

        //$link = JURI::root() . "index.php/" . "$this->listName?show_request_id=$req_id";
        $link = JURI::root() . "index.php" . $listModel->getTableAction();
       
        $subject = Text::sprintf('PLG_FORM_WORKFLOW_EMAIL_REQUEST_SUBJECT', $this->listName) . " :: " . $this->config->get('sitename');
        $message = Text::sprintf('PLG_FORM_WORKFLOW_EMAIL_REQUEST_BODY', $request_type, $this->listName, $link);

        $this->enviarEmail($emailTo, $subject, $message);
    }

    private function enviarEmailRequestApproval($request, $record_id)
    {
        $owner_user_email = !empty($request["req_user_email"]) ? $request["req_user_email"] : null;
        //email para o solicitante da mudanca
        $emailTo = array();

        //email para o dono do registro
        if ($owner_user_email) {
            $emailTo[] = $owner_user_email;
        }

        $list_id = $request["req_list_id"];
        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
        $listModel->setId($list_id);

        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $query->select('label')
            ->from('#__fabrik_lists')
            ->where("id = $list_id");
        $db->setQuery($query);
        $db->execute();

        $results = $db->loadResult();

        $list_label = $results;
        $user_name = $request["req_user_name"];
        $request_type = $request["req_request_type_id"];
        switch ($request_type) {
            case '1':
                $request_type = "Inclusão";
                break;
            case '2':
                $request_type = "Edição";
                break;
            case '3':
                $request_type = "Reportar Abuso";
                break;
        
            default:
                break;
        }
        $request_status = $request['req_approval'] === '1' ? 'Aprovada' : 'Não Aprovada';

        //email para o dono da lista
        //...

        // $link = JURI::root() . "index.php/" . "$list_label/details/$list_id/$record_id";
        $link = JURI::root() . "index.php" .  $listModel->viewDetailsLink($record_id).$record_id;
        
        $request_type = $request["req_request_type_id"];
        switch ($request_type) {
            case '1':
                $request_type = "Inclusão";
                break;
            case '2':
                $request_type = "Edição";
                break;
            case '3':
                $request_type = "Reportar Abuso";
                break;
        
            default:
                break;
        }

        $subject = Text::sprintf('PLG_FORM_WORKFLOW_EMAIL_REQUEST_APPROVAL_SUBJECT', $list_label) . " :: " . $this->config->get('sitename');
        $message = Text::sprintf('PLG_FORM_WORKFLOW_EMAIL_REQUEST_APPROVAL_BODY', $user_name, $request_type, $list_label, $request_status, $link);

        $this->enviarEmail($emailTo, $subject, $message);
    }

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
                $mail = JFactory::getMailer();
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
     * Define se a lista é de request
     */
    private function checkIsRequestList()
    {
        $this->isRequestList = (stripos($this->listName, '_request') !== false);
    }

    /**
     * Retorna se a lista é de request
     * @return type
     */
    private function isRequestList()
    {
        return $this->isRequestList;
    }

    /**
     * @deprecated replaced by $this->listRequests()
     */
    public function listarRequests()
    {
        $dbtable = $this->requestListName;
        $wfl_action = filter_input(INPUT_GET, 'wfl_action', FILTER_SANITIZE_STRING);
        $req_status = filter_input(INPUT_GET, 'wfl_status', FILTER_SANITIZE_STRING);
        $req_status = $req_status ?: 'verify';
        $_REQUEST['wfl_action'] = $wfl_action;
        $_REQUEST['wfl_status'] = $req_status;

        //headings
        $headings = array(
            'req_request_type_name' => 'Request Type',
            'req_user_name' => 'User',
            'req_created_date' => 'Date Time',
            'req_owner_name' => 'Owner',
            'req_reviewer_name' => 'Reviewer',
            'req_revision_date' => 'Revision Date',
            'req_status' => 'Status',
            'req_record_id' => 'Record',
            'req_approval' => 'Approval'
        );

        //grupos
        $statusLista = array(
            'verify' => 'Verify',
            'approved' => 'Approved',
            'not-approved' => 'Not Approved',
            'pre-approved' => 'Pre-Approved',
        );

        //data
        $db = JFactory::getDbo();
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
            ->select('workflow_request_type.name as req_request_type_name')
            ->from($dbtable)
            ->join('INNER', "workflow_request_type on (req_request_type_id = workflow_request_type.id)")
            ->join('INNER', $db->quoteName('#__users', 'u_req') . ' ON (' . $db->quoteName('req_user_id') . ' = ' . $db->quoteName('u_req.id') . ')')
            ->join('LEFT', $db->quoteName('#__users', 'u_owner') . ' ON (' . $db->quoteName('req_owner_id') . ' = ' . $db->quoteName('u_owner.id') . ')')
            ->join('LEFT', $db->quoteName('#__users', 'u_rev') . ' ON (' . $db->quoteName('req_reviewer_id') . ' = ' . $db->quoteName('u_rev.id') . ')')
            ->order("FIELD(req_status, 'verify', 'approved', 'not-approved', 'pre-approved')")
            ->order("req_id asc");

        if ($wfl_action == 'list_requests') {
            $query->where("req_status = '{$req_status}'");
        }
        if ($this->canViewRequests() == 'only_own') {
            $query->where("req_user_id = '{$this->user->id}'");
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
        //$_REQUEST['workflow']['requests_isGrouped'] = true;
        //$_REQUEST['workflow']['requests_grouptemplates'] = $statusLista;
        ////$_REQUEST['workflow']['requests_group_by_show_count'] = false;
    }

    private function getRowData($rowId)
    {
        $myDb = JFactory::getDbo();
        $query = $myDb->getQuery(true);
        $query->select("*")->from($this->listName)
            ->where("{$this->fieldPrefix}id = " . $myDb->quote($rowId));
        $myDb->setQuery($query);
        $row = $myDb->loadAssoc();
        return $row;
    }

    /**
     * Aplica as mudancas do request ao registro da tabela principal
     * @param type $formData
     * @return boolean
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

            $db = JFactory::getDbo();
            $query = $db->getQuery(true);

            if ($requestType == self::REQUEST_TYPE_DELETE_RECORD) {
                $query->delete($db->quoteName($dbTable))
                    ->where(array($db->quoteName($pkey) . " = '{$record_id}'"));
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
                    $values[] = $db->quote($v);
                    $strValues[] = "{$db->quoteName($k)} = {$db->quote($v)}";
                    echo ("k: $k - v: $v <br>");
                }
                if ($requestType == self::REQUEST_TYPE_ADD_RECORD) {
                    $query->insert($db->quoteName($dbTable))
                        ->columns($db->quoteName($columns))
                        ->values(implode(',', $values));
                } else {
                    $query->update($db->quoteName($dbTable))
                        ->set($strValues)
                        ->where(array($db->quoteName($pkey) . " = {$record_id}"));
                }
            }
            $db->setQuery($query);
            $db->execute();
            return true;
        } catch (Exception $e) {
            JFactory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }
    }

    public function onDeleteRow()
    {
        $app = JFactory::getApplication();
        JModelLegacy::addIncludePath(JPATH_SITE . '/components/com_fabrik/models');
        $fabrikModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
        $rowId = $app->input->getInt('rowId');
        $listId = $app->input->getInt('listId');
        $fabrikModel->setId($listId);
        $ok = $fabrikModel->deleteRows($rowId);
    }

    public function onReportAbuse()
    {
        $app = JFactory::getApplication();
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
        $owner_id = $formData[$table_name . '___' . $owner_element_name.'_raw'];

        $date = Factory::getDate();
        $usuario = &JFactory::getUser();
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
            $this->creatLog($formData, $hasPermission);
            $listModel->deleteRows($rowId);
        }
    }

    public function onBeforeGetData(){
        $this->init();
        $listModel = $this->getModel()->getListModel();        
        $listModel->setId($this->listId);
        $row = $listModel->getRow($this->app->input->get('rowid'));
        $can_delete = in_array($this->user->id, $this->getReviewers($row));
        $_REQUEST['workflow_can_delete_upload'] = $can_delete;
        unset($listModel);
        return true;
    }

    public function onRenderAdminSettings($data = array(), $repeatCounter = null, $mode = null)
    {
        $this->install();

        return parent::onRenderAdminSettings($data, $repeatCounter, $mode);
    }

    /**
     * Install the plugin db tables
     *
     * @return  void
     */
    public function install()
    {
        $db = FabrikWorker::getDbo();


        /* Create the tables */
        $sql = "CREATE TABLE IF NOT EXISTS `#__fabrik_requests` (
			`req_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY ,
			`req_request_type_id` INT(11),
			`req_user_id` INT(11),
			`req_field_id` INT(11) ,
			`req_created_date` TIMESTAMP ,
			`req_owner_id` INT(11) ,
			`req_reviewer_id` INT(11),
			`req_revision_date` TIMESTAMP,
			`req_status` VARCHAR(255),
			`req_description` TEXT,
			`req_comment` TEXT,
			`req_record_id` INT(11),
			`req_approval` TEXT,
			`req_file` TEXT,
			`req_list_id` INT(11),
			`form_data` TEXT
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
                "INSERT INTO `workflow_request_type` VALUES (1,'add_record'),(2,'edit_field_value'),(3,'delete_record');",
            ];

            foreach ($sqls as $sql) {
                $db->setQuery($sql)->execute();
            }
        }
    }
}
