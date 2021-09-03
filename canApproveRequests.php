<?php

// Identifica tipo de conteúdo como JSON
header('Content-Type: application/json');
// Importando obj necessários para acessar o database
define('_JEXEC', 1);
define('JPATH_BASE', '../../../');
require_once JPATH_BASE . 'includes/defines.php';
require_once JPATH_BASE . 'includes/framework.php';

$response = new StdClass;

$response->canApproveRequests = canApproveRequests();

echo json_encode($response);

function canApproveRequests() {
    $groups = JFactory::getUser()->getAuthorisedGroups();

    if ($this->user->authorise('core.admin')) {
        return true;
    } else if (in_array($this->getParams()->get('allow_review_request'), $groups)) {
        return true;
    }

    return false;

}