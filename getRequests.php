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
$query->select(array('a.*', 'b.name as req_user_id_raw', 'b.email as email', 'c.name as req_owner_id_raw', 'd.name as req_reviewer_id_raw'));

// Da tabela $join_name
$query->from($db->quoteName('#__fabrik_requests', 'a'));

// If has req_id, set where
if(isset($_GET['req_id'])) {
    $req_id = $_GET['req_id'];
    $query->join('INNER', $db->quoteName('#__users', 'b') . ' ON (' . $db->quoteName('a.req_user_id') . ' = ' . $db->quoteName('b.id') . ')');
    $query->join('LEFT', $db->quoteName('#__users', 'c') . ' ON (' . $db->quoteName('a.req_owner_id') . ' = ' . $db->quoteName('c.id') . ')');
    $query->join('LEFT', $db->quoteName('#__users', 'd') . ' ON (' . $db->quoteName('a.req_reviewer_id') . ' = ' . $db->quoteName('d.id') . ')');
    $query->where($db->quoteName('req_id') . ' = '. $db->quote($req_id));

} else {
    die('Error getting requests, no req_id was passed.');
}

// Aplica a query no obj DB
$db->setQuery($query);

// Salva resultado da query em results
$results = $db->loadObjectList();

// Codifica $results para JSON
echo json_encode($results);
