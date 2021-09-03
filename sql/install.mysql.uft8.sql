-- DROP TABLE IF EXISTS `#__fabrik_requests`;
CREATE TABLE IF NOT EXISTS `#__fabrik_requests` (
	req_id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, 
  req_request_type_id int(11),
  req_user_id int(11),
  req_field_id int(11),
  req_created_date datetime,
  req_owner_id int(11),
  req_reviewer_id int(11),
  req_revision_date datetime,
  req_status varchar(255),
  req_description text,
  req_comment text,
  req_record_id int(11),
  req_approval text,
  req_file text,
  req_list_id int(11),
	form_data text	
);
DROP TABLE IF EXISTS `workflow_request_type`;
CREATE TABLE `workflow_request_type` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fb_order_nome_INDEX` (`name`(10)) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8;

LOCK TABLES `workflow_request_type` WRITE;
INSERT INTO `workflow_request_type` VALUES (1,'add_record'),(2,'edit_field_value'),(3,'delete_record');
UNLOCK TABLES;
