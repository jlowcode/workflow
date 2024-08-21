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

// // Seleciona identificador e valor
$query->select('name');

// Da tabela $join_name
$query->from($db->quoteName('workflow_request_type'));

// If has req_id, set where
if(isset($_GET['req_request_type_id'])) {
    $req_request_type_id = $_GET['req_request_type_id'];
    $query->where($db->quoteName('id') . ' = '. $db->quote($req_request_type_id));
}

// Aplica a query no obj DB
$db->setQuery($query);

// Salva resultado da query em results
$results = $db->loadObjectList();

// Codifica $results para JSON
echo json_encode($results);