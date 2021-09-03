<?php

const REQUEST_TYPE_ADD_RECORD = 1;
const REQUEST_TYPE_EDIT_RECORD = 2;
const REQUEST_TYPE_DELETE_RECORD = 3;

// Identifica tipo de conteúdo como JSON
header('Content-Type: application/json');
// Importando obj necessários para acessar o database
define('_JEXEC', 1);
define('JPATH_BASE', '../../../');
require_once JPATH_BASE . 'includes/defines.php';
require_once JPATH_BASE . 'includes/framework.php';

// Pega parâmetros passados
$requestData = $_GET['formData'][0];

// Converte JSON para objeto
$formData = json_decode($requestData['form_data']);

processRequest($requestData, $formData);

// var_dump($jsonRequestData);

// echo json_encode(getListName(23, $db));

// processRequest($array)
echo json_encode(true);




function processRequest($requestData, $formData) {
    // Recebe o obj para acessar o DB
    $db = JFactory::getDBO();
    if ($requestData['req_approval'] === '1' || $requestData['req_approval'] === '0') {
        $status = $requestData['req_approval'] === '1' ? 'approved' : 'not-approved';
        $query = $db->getQuery(true);
        $query->set("req_status = " . $db->quote($status));
        // Updates request on requests table
        $query->update('#__fabrik_requests')->where("req_id = " . $db->quote($requestData['req_id']));
        $db->setQuery($query);
        $db->execute();
        if($status == 'approved') {
            saveToMainList($formData, $requestData);
        }
        
        // //envia email com o resultado da operacao
        // $this->enviarEmailRequestApproval($formData, $status);
    }
    return true;
}

function saveToMainList($formData, $requestData) {
    try{
        // Recebe o obj para acessar o DB
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $subQuery = $db->getQuery(true);

        $subQuery
            ->select($db->quoteName('b.group_id'))
            ->from($db->quoteName('#__fabrik_lists', 'a'))
            ->join('INNER', $db->quoteName('#__fabrik_formgroup', 'b') .
                ' ON ' . $db->quoteName('a.form_id') . ' = ' . $db->quoteName('b.form_id'))
            ->where($db->quoteName('a.id') . ' = ' . $requestData['req_list_id']);

        $query
            ->select(array($db->quoteName('name'), $db->quoteName('plugin')))
            ->from($db->quoteName('#__fabrik_elements'))
            ->where($db->quoteName('group_id') . ' = ' . "($subQuery)" );

//        SELECT `plugin` FROM h4rjm_fabrik_elements WHERE group_id = (SELECT h4rjm_fabrik_formgroup.group_id FROM h4rjm_fabrik_lists inner join h4rjm_fabrik_formgroup on h4rjm_fabrik_lists.form_id = h4rjm_fabrik_formgroup.form_id where h4rjm_fabrik_lists.id = 23)

        $db->setQuery($query);
        $db->execute();

        $results = $db->loadObjectList();
//
//        echo "<pre>";
//        var_dump($results);
//        echo "</pre>";
//
//
//        die('Teste query');

        $listName = getListName($requestData["req_list_id"])->db_table_name;
        $record_id = $requestData["req_record_id"];
        if ($requestData['req_request_type_id'] == REQUEST_TYPE_DELETE_RECORD) { 
            $query->delete($db->quoteName($listName))
            ->where(array($db->quoteName('id') . " = '{$record_id}'"));
        } else {
            $columns = $values = $strValues = array();
            foreach ($formData as $k => $v) {
                // if (stripos($k, 'req_') !== false) {
                //     continue;
                // }
                if($k == 'id') {
                    continue;
                }
                if(is_array(json_decode($v))) {
                    $options = json_decode($v);

                        $table_name = $listName . '_' . 'repeat' . '_' . $k;
                        $query->select(1)
                            ->from($table_name);
                        $db->setQuery($query);
                        $db->execute();
                        $results = $db->loadObjectList();
                        if($results) {
                            echo 'Table exists';
                            // Delete the older options
                            $query = $db->getQuery(true);
                            $query->delete($db->quoteName($table_name))
                                ->where(array($db->quoteName('parent_id') . " = '{$record_id}'"));

                            $db->setQuery($query);
                            $db->execute();

                            // Insert the new options
                            foreach ($options as $option) {
                                $query = $db->getQuery(true);

                                $columns = array('parent_id', $k);

                                $values = array($record_id, $option);

                                $query
                                    ->insert($db->quoteName($table_name))
                                    ->columns($db->quoteName($columns))
                                    ->values(implode(',', $values));


                                $db->setQuery($query);
                                $db->execute();
                            }

                        } else {
//                            echo 'Table doesnt exists';
                        }

                    echo "$v é array";
                }
                $columns[] = $k;
                $values[] = $db->quote($v);
                $strValues[] = "{$db->quoteName($k)} = {$db->quote($v)}";
            }
            $query = $db->getQuery(true);
            if ($requestData['req_request_type_id'] == REQUEST_TYPE_ADD_RECORD) {
                $query->insert($db->quoteName($listName))
                        ->columns($db->quoteName($columns))
                        ->values(implode(',', $values));
            } else {
                $query->update($db->quoteName($listName))
                        ->set($strValues)
                        ->where(array($db->quoteName('id') . " = {$record_id}"));
            }
        }
//        die('Testeeee');
        $db->setQuery($query);
        $db->execute();
        return true;
    } catch(Exception $e) {
        JFactory::getApplication()->enqueueMessage($e->getMessage(), 'error');
        return false;
    }
}



function getListName($listId) {
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

// function applyRequestChange($rowid) {
//     try {
//         $dbTable = str_replace($this->dbtable_request_sufixo, '', $this->listName);
//         $pkey = 'id';
//         if(!($rowData = $this->getRowData($rowid))) {
//             throw new Exception('Request not found!');
//         }
//         $requestType = $rowData['req_request_type_id'];
//         $record_id = $rowData["{$this->fieldPrefix}record_id"];
        
//         $db = JFactory::getDbo();
//         $query = $db->getQuery(true);
        
//         if ($requestType == self::REQUEST_TYPE_DELETE_RECORD) {
//             $query->delete($db->quoteName($dbTable))
//                     ->where(array($db->quoteName($pkey) . " = '{$record_id}'"));
//         } else {
//             $columns = $values = $strValues = array();
//             foreach ($rowData as $k => $v) {
//                 if (stripos($k, $this->fieldPrefix) !== false) {
//                     continue;
//                 }
//                 if($k == $pkey) {
//                     continue;
//                 }
//                 $columns[] = $k;
//                 $values[] = $db->quote($v);
//                 $strValues[] = "{$db->quoteName($k)} = {$db->quote($v)}";
//                 echo("k: $k - v: $v <br>");
//             }
//             die('applyRequestChange');
//             if ($requestType == self::REQUEST_TYPE_ADD_RECORD) {
//                 $query->insert($db->quoteName($dbTable))
//                         ->columns($db->quoteName($columns))
//                         ->values(implode(',', $values));
//             } else {
//                 $query->update($db->quoteName($dbTable))
//                         ->set($strValues)
//                         ->where(array($db->quoteName($pkey) . " = {$record_id}"));
//             }
//         }
//         $db->setQuery($query);
//         $db->execute();
//         return true;
//     } catch (Exception $e) {
//         JFactory::getApplication()->enqueueMessage($e->getMessage(), 'error');
//         return false;
//     }
// }
