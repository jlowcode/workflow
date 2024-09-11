<?php

// Identifica tipo de conteúdo como JSON
header('Content-Type: application/json');
// Importando obj necessários para acessar o database
define('_JEXEC', 1);
define('JPATH_BASE', '../../../');
require_once JPATH_BASE . 'includes/defines.php';
require_once JPATH_BASE . 'includes/framework.php';

// Recebe o obj para acessar o DB
$db = JFactory::getDBO();

// Cria novo obj query
$query = $db->getQuery(true);

// Seleciona db_table_name
$query->select('db_table_name');

// Da tabela #__fabrik_lists
$query->from($db->quoteName('#__fabrik_lists'));

// If has req_id, set where
if(isset($_GET['req_list_id'])) {
    $req_list_id = $_GET['req_list_id'];
    $query->where($db->quoteName('id') . ' = '. $db->quote($req_list_id));
} else {
    die('Error getting requests, no req_list_id was passed.');
}

// Aplica a query no obj DB
$db->setQuery($query);

// Salva resultado da query
$fabrikListName = $db->loadObjectList();


// Limpa obj query
$query = $db->getQuery(true);

// Seleciona tudo
$query->select('*');

// Da tabela encontrada na outra consulta
$query->from($db->quoteName($fabrikListName[0]->db_table_name));

if(isset($_GET['req_record_id'])) {
    $req_record_id = $_GET['req_record_id'];
    $query->where($db->quoteName('id') . ' = '. $db->quote($req_record_id));
} else {
    die('Error getting requests, no req_record_id was passed.');
}

// Aplica a query no obj DB
$db->setQuery($query);

// Salva resultado da query
$req_record_id_results = $db->loadObjectList();

// Codifica $results para JSON
echo json_encode($req_record_id_results);